<?php

namespace Headoo\ElasticSearchBundle\Command;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class PopulateElasticCommand extends AbstractCommand
{

    protected function configure()
    {
        $this->setName('headoo:elastic:populate')
            ->setDescription('Repopulate Elastic Search')
            ->addOption('limit',   null, InputOption::VALUE_OPTIONAL, 'Limit For selected Type', 0)
            ->addOption('offset',  null, InputOption::VALUE_OPTIONAL, 'Offset For selected Type', 0)
            ->addOption('type',    null, InputOption::VALUE_OPTIONAL, 'Type of document you want to populate. You must to have configure it before use', null)
            ->addOption('threads', null, InputOption::VALUE_OPTIONAL, 'number of simultaneous threads', null)
            ->addOption('reset',   null)
            ->addOption('batch',   null, InputOption::VALUE_OPTIONAL, 'Number of Document per batch', null);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init($input, $output);

        // We add a limit per batch which equal of the batch option
        if($input->getOption('batch')){
            $this->limit = $this->batch ;
        }

        if($input->getOption('type')){
            return $this->_switchType($this->type, $this->batch);
        }

        $returnValue = self::EXIT_SUCCESS;
        foreach ($this->aTypes as $type){
            $returnedValue = $this->_switchType($type, $this->batch);
            if ($returnedValue != self::EXIT_SUCCESS) {
                $returnValue = self::EXIT_FAILED;
            }
        }

        return $returnValue;
    }

    /**
     * @param string $type
     * @param int $batch
     * @return int
     */
    private function _switchType($type, $batch)
    {
        if(in_array($type, $this->aTypes)){
            $this->output->writeln(self::completeLine("BEGIN {$type}"));
            if($this->reset){
                $this->_resetType($type);
            }

            $returnValue = ($batch) ?
                $this->beginBatch($type) :
                $this->processBatch($type, $this->getContainer()->get($this->mappings[$type]['transformer']));

            $this->output->writeln(self::completeLine("FINISH {$type}"));

            return $returnValue;
        }

        $this->output->writeln(self::completeLine("Wrong Type"));

        return self::EXIT_FAILED;
    }

    /**
     * @param $type
     * @param $properties
     */
    private function _mappingFields($type, $properties)
    {
        // Define mapping
        $mapping = new \Elastica\Type\Mapping();
        $mapping->setType($type);

        // Set mapping
        $mapping->setProperties($properties);
        $mapping->send();
    }

    /**
     * @param \Elastica\Type $type
     * @param $aDocuments
     */
    private function _bulk($type, $aDocuments)
    {
        if(count($aDocuments)){
            $type->addDocuments($aDocuments);
            $type->getIndex()->refresh();
        }
    }

    /**
     * @param $progressBar
     * @param array $processes
     * @param $maxParallel
     * @param int $poll
     * @param int $numberOfEntities
     * @return int
     */
    public function runParallel(ProgressBar $progressBar, array $processes, $maxParallel, $poll = 1000, $numberOfEntities)
    {
        // do not modify the object pointers in the argument, copy to local working variable
        $processesQueue = $processes;
        // fix maxParallel to be max the number of processes or positive
        $maxParallel = min(abs($maxParallel), count($processesQueue));
        // get the first stack of processes to start at the same time
        /** @var Process[] $currentProcesses */
        $currentProcesses = array_splice($processesQueue, 0, $maxParallel);
        // start the initial stack of processes
        foreach ($currentProcesses as $process) {
            $process->start();
        }

        $progression = $this->offset;
        $progressMax = $numberOfEntities + $this->offset;
        $returnValue = self::EXIT_SUCCESS;

        do {
            // wait for the given time
            usleep($poll);
            // remove all finished processes from the stack
            foreach ($currentProcesses as $index => $process) {
                if (!$process->isRunning()) {
                    if ($process->getExitCode() != self::EXIT_SUCCESS) {
                        $this->output->writeln($process->getErrorOutput());
                        $returnValue = self::EXIT_FAILED;
                    }

                    unset($currentProcesses[$index]);

                    $progression += $this->limit;
                    $progressBar->setMessage("$progression/$progressMax");
                    $progressBar->advance($this->limit);

                    // directly add and start new process after the previous finished
                    if (count($processesQueue) > 0) {
                        $nextProcess = array_shift($processesQueue);
                        $nextProcess->start();

                        $currentProcesses[] = $nextProcess;
                    }
                }
            }

            // continue loop while there are processes being executed or waiting for execution
        } while (count($processesQueue) > 0 || count($currentProcesses) > 0);

        $progressBar->setMessage("$numberOfEntities/$progressMax");
        $progressBar->setProgress($numberOfEntities);
        $progressBar->finish();

        return $returnValue;
    }

    /**
     * @param $type
     * @return int
     */
    public function beginBatch($type)
    {
        $numberObjects = $this->entityManager->createQuery("SELECT COUNT(u) FROM {$this->mappings[$type]['class']} u")->getResult()[0][1];
        $aProcess = [];
        $numberOfEntities = $numberObjects - $this->offset;
        $numberOfProcess = floor($numberOfEntities / $this->limit);
        $sOptions = $this->getOptionsToString(['type', 'limit', 'offset', 'threads', 'batch']);

        $progressBar = $this->getProgressBar($this->output, $numberOfEntities);

        for ($i = 0; $i <= $numberOfProcess; $i++) {
            $_offset = $this->offset + ($this->limit * $i);
            $process = new Process("php app/console headoo:elastic:populate --type={$type} --limit={$this->limit} --offset={$_offset} " . $sOptions);
            $aProcess[] = $process;
        }

        return $this->runParallel($progressBar, $aProcess, $this->threads, 1000, $numberOfEntities);
    }

    /**
     * @param $type
     * @param $transformer
     */
    public function processBatch($type, $transformer)
    {
        $this->output->writeln(self::completeLine("Creating Type {$type} and Mapping"));

        $objectType = $this->getIndexFromType($type)->getType($type);
        $this->_mappingFields($objectType, $this->mappings[$type]['mapping']);

        $this->output->writeln(self::completeLine("Finish Type {$type} and Mapping"));
        $this->output->writeln(self::completeLine("Start populate {$type}"));

        $iResults = $this->entityManager->createQuery("SELECT COUNT(u) FROM {$this->mappings[$type]['class']} u")->getResult()[0][1];
        $query = $this->entityManager->createQuery("select u from {$this->mappings[$type]['class']} u");

        if($this->offset){
            $query->setFirstResult($this->offset);
            $iResults = $iResults - $this->offset;
        }

        if($this->limit){
            $query->setMaxResults($this->limit);
            $iResults = $this->limit;
        }

        $iterableResult = $query->iterate();

        $progressBar = $this->getProgressBar($this->output, $iResults);
        $progression = $this->offset;
        $progressMax = $iResults + $this->offset;

        $aDocuments = [];

        foreach ($iterableResult as $row){
            $document = $transformer->transform($row[0]);

            if (!$document) {
                continue;
            }

            $aDocuments[]= $document;
            $this->entityManager->detach($row[0]);

            $progressBar->setMessage(($progression++) . "/{$progressMax}");
            $progressBar->advance();

            gc_collect_cycles();
        }

        $this->_bulk($objectType, $aDocuments);
        $this->output->writeln(self::completeLine("Start populate '{$type}'"));

        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln("<info>" . self::completeLine("Finish populate {$type}") . "</info>");
    }

    /**
     * @param string $type
     * @return bool
     */
    private function _resetType($type)
    {
        $this->output->writeln(self::completeLine("RESET INDEX"));

        $index_name = $this->getContainer()->get('headoo.elasticsearch.handler')->getIndexName($type);
        $connection = $this->mappings[$type]['connection'];
        $index      = $this->mappings[$type]['index'];

        $response = $this->elasticSearchHelper->getClient($connection)->getIndex($index_name)->create($index, true);

        if ($response->hasError()) {
            $this->output->writeln("Cannot reset index '{$type}': " . $response->getErrorMessage());
            return false;
        }

        return true;
    }

}
