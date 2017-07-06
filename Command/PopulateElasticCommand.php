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
        $this
            ->setName('headoo:elastic:populate')
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
            $this->_switchType($this->type, $this->batch);
            return AbstractCommand::EXIT_SUCCESS;
        }

        foreach ($this->aTypes as $type){
            $this->_switchType($type, $this->batch);
        }

        return AbstractCommand::EXIT_SUCCESS;
    }

    /**
     * @param $type
     * @param $batch
     */
    private function _switchType($type, $batch)
    {
        if(in_array($type, $this->aTypes)){
            $this->output->writeln($this->completeLine("BEGIN {$type}"));
            if($this->reset){
                $this->output->writeln($this->completeLine("RESET INDEX"));
                $index_name = $this->getContainer()->get('headoo.elasticsearch.handler')->getIndexName($type);
                $connection = $this->mappings[$type]['connection'];
                $index      = $this->mappings[$type]['index'];
                $this->elasticSearchHelper->getClient($connection)->getIndex($index_name)->create($index, true);
            }
            if(!$batch){
                $this->processBatch($type, $this->getContainer()->get($this->mappings[$type]['transformer']));
            }else{
                $this->beginBatch($type);
            }
            $this->output->writeln($this->completeLine("FINISH {$type}"));
            return;
        }

        $this->output->writeln($this->completeLine("Wrong Type"));
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

        do {
            // wait for the given time
            usleep($poll);
            // remove all finished processes from the stack
            foreach ($currentProcesses as $index => $process) {
                if (!$process->isRunning()) {
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
    }

    /**
     * @param $type
     */
    public function beginBatch($type)
    {
        $numberObjects = $this->entityManager->createQuery("SELECT COUNT(u) FROM {$this->mappings[$type]['class']} u")->getResult()[0][1];
        $aProcess = [];
        $numberOfEntities = $numberObjects - $this->offset;
        $numberOfProcess = floor($numberOfEntities / $this->limit);

        $progressBar = $this->getProgressBar($this->output, $numberOfEntities);

        for ($i = 0; $i <= $numberOfProcess; $i++) {
            $_offset = $this->offset + ($this->limit * $i);
            $process = new Process("php app/console headoo:elastic:populate --type={$type} --limit={$this->limit} --offset={$_offset}");
            $aProcess[] = $process;
        }

        $max_parallel_processes = $this->threads;
        $polling_interval = 1000; // microseconds
        $this->runParallel($progressBar, $aProcess, $max_parallel_processes, $polling_interval, $numberOfEntities);

        return;
    }

    /**
     * @param $type
     * @param $transformer
     */
    public function processBatch($type, $transformer)
    {
        $this->output->writeln($this->completeLine("Creating Type {$type} and Mapping"));

        $objectType = $this->getIndexFromType($type)->getType($type);
        $this->_mappingFields($objectType, $this->mappings[$type]['mapping']);

        $this->output->writeln($this->completeLine("Finish Type {$type} and Mapping"));
        $this->output->writeln($this->completeLine("Start populate {$type}"));

        $iResults = $this->entityManager->createQuery("SELECT COUNT(u) FROM {$this->mappings[$type]['class']} u")->getResult()[0][1];
        $q = $this->entityManager->createQuery("select u from {$this->mappings[$type]['class']} u");

        if($this->offset){
            $q->setFirstResult($this->offset);
            $iResults = $iResults - $this->offset;
        }

        if($this->limit){
            $q->setMaxResults($this->limit);
            $iResults = $this->limit;
        }

        $iterableResult = $q->iterate();

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
        }

        $this->_bulk($objectType,$aDocuments);
        $this->output->writeln($this->completeLine("Start populate {$type}"));

        $progressBar->finish();
        $this->output->writeln('');
        $this->output->writeln("<info>" . $this->completeLine("Finish populate {$type}") . "</info>");
    }

}
