<?php

namespace Headoo\ElasticSearchBundle\Command;

use Doctrine\ORM\Query;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use Symfony\Component\Console\Input\InputDefinition;

class PopulateElasticCommand extends AbstractCommand
{

    protected function configure()
    {
        $this->setName('headoo:elastic:populate')
            ->setDescription('Repopulate Elastic Search')
            ->setDefinition(
                new InputDefinition([
                    new InputOption('limit',   null, InputOption::VALUE_OPTIONAL, 'Limit For selected Type', 0),
                    new InputOption('offset',  null, InputOption::VALUE_OPTIONAL, 'Offset For selected Type', 0),
                    new InputOption('type',    null, InputOption::VALUE_OPTIONAL, 'Type of document you want to populate. You must to have configure it before use', null),
                    new InputOption('threads', null, InputOption::VALUE_OPTIONAL, 'number of simultaneous threads', null),
                    new InputOption('reset',   null, InputOption::VALUE_NONE,     'Reset the index'),
                    new InputOption('batch',   null, InputOption::VALUE_OPTIONAL, 'Number of Document per batch', null),
                    new InputOption('id',      null, InputOption::VALUE_REQUIRED, 'Refresh a specific object with his Id', null),
                    new InputOption('where',   null, InputOption::VALUE_REQUIRED, 'Refresh objects with specific field ', null),
                    new InputOption('join',    null, InputOption::VALUE_REQUIRED, 'Join on another entity', null)
                ])
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     * @throws \Doctrine\ORM\OptimisticLockException
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init($input, $output);

        if ($input->getOption('where') && ! $input->getOption('id')) {
            $output->writeln("<error>You must provide an 'id' with the 'where' option</error>");
            return self::EXIT_FAILED;
        }

        if ($input->getOption('id')) {
            if ($input->getOption('reset')) { // $input->getOption('batch') ||  || $input->getOption('threads')
                $output->writeln("<error>The option 'id' cannot be used with option 'reset'</error>");
                return self::EXIT_FAILED;
            }

            if (!$input->getOption('type')) {
                $output->writeln("<error>The option 'id' have to be used with option 'type'</error>");
                return self::EXIT_FAILED;
            }
        }

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
        if (in_array($type, $this->aTypes)) {
            $this->output->writeln(self::completeLine("BEGIN {$type}"));
            if ($this->reset) {
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
            unset($aDocuments);
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

                    $processDone = intval($process->getOutput());
                    $progression += $processDone;
                    $progressBar->setMessage("$progression/$progressMax");
                    $progressBar->advance($processDone);

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

        $progressBar->finish();
        $progressBar->display();
        $this->output->writeln('');

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
        $sOptions = $this->getOptionsToString(['type', 'limit', 'offset', 'threads', 'batch', 'reset']);

        $progressBar = $this->getProgressBar($this->output, $numberOfEntities);

        for ($i = 0; $i <= $numberOfProcess; $i++) {
            $_offset = $this->offset + ($this->limit * $i);
            $process = new Process("php $this->consoleDir headoo:elastic:populate --type={$type} --limit={$this->limit} --offset={$_offset} --quiet " . $sOptions);
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

        // Select a specific object from his ID
        $query = $this->_getQuery($type, $iResults);

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
        $progression = 0;
        $progressMax = $iResults + $this->offset;

        foreach ($iterableResult as $row) {
            try {
                $document = $transformer->transform($row[0]);
            } catch (\Doctrine\ORM\EntityNotFoundException $e) {
                # An object has not been found
                $this->output->writeln(get_class($row[0]) . "({$row[0]->getId()}): {$e->getMessage()}");
                $document = null;
            }

            if (!$document) {
                continue;
            }

            $objectType->addDocument($document);
            $this->entityManager->clear();

            $progressBar->setMessage((++$progression + $this->offset) . "/{$progressMax}");
            $progressBar->advance();

            gc_collect_cycles();
        }

        $objectType->getIndex()->refresh();

        try {
            $progressBar->setProgress($iResults);
        } catch (\Symfony\Component\Console\Exception\LogicException $e) {
            # You can't regress the progress bar.
        }

        $progressBar->display();
        $progressBar->finish();

        $this->output->writeln('');
        $this->output->writeln("<info>" . self::completeLine("Finish populate {$type}") . "</info>");
        # In quite mode: just write in output the number of documents treated
        if ($this->quiet) {
            $this->output->writeln("$progression", OutputInterface::VERBOSITY_QUIET);
        }
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

    /**
     * @param string $type
     * @param int $iResults
     * @return Query
     */
    private function _getQuery($type, &$iResults)
    {
        $id = filter_var($this->id, FILTER_SANITIZE_STRING);
        $where = filter_var($this->where, FILTER_SANITIZE_STRING);
        $joins = filter_var($this->join, FILTER_SANITIZE_STRING);
        $entity = 'u';

        # Forge clause JOIN
        $clauseJoin = '';
        $aJoins = explode(',', $joins);
        foreach ($aJoins as $join) {
            if (empty($join)) {
                break;
            }
            $newId = ($newId ?? 0) + 1;
            $newEntity = "u_$newId";
            $clauseJoin .= " LEFT JOIN {$entity}.{$join} {$newEntity} ";
            $entity = $newEntity;
        }

        # Forge clause WHERE
        $clauseWhere = '';
        if ($id && $where) {
            $clauseWhere = " WHERE {$entity}.{$where} = '{$id}'";
        }
        if ($id && !$where) {
            $clauseWhere = " WHERE {$entity}.id = '{$id}'";
        }

        # COUNT results
        try {
            $iResults = $this->entityManager->createQuery("SELECT COUNT(u) FROM {$this->mappings[$type]['class']} u $clauseJoin $clauseWhere")->getResult()[0][1];
        } catch (\Doctrine\ORM\Query\QueryException $e) {
            $iResults = 0;
        }

        # Return Query
        return $this->entityManager->createQuery("SELECT u FROM {$this->mappings[$type]['class']} u $clauseJoin $clauseWhere");
    }
}
