<?php

namespace Headoo\ElasticSearchBundle\Command;

use Doctrine\ORM\EntityManager;
use Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class PopulateElasticCommand extends ContainerAwareCommand
{
    /** @var  OutputInterface */
    protected $output;
    /** @var  integer */
    protected $threads;
    /** @var  integer */
    protected $limit;
    /** @var  integer */
    protected $offset;
    /** @var bool  */
    protected $batch = false;
    /** @var bool  */
    protected $reset = false;
    /** @var  string */
    protected $type;
    /** @var  array */
    protected $aTypes;
    /** @var  ElasticSearchHelper */
    protected $elasticaHelper;
    /** @var  array */
    protected $mappings;
    /** @var  EntityManager */
    protected $em;

    protected function configure()
    {
        $this
            ->setName('headoo:elastic:populate')
            ->setDescription('Repopulate Elastic Search')
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Limit For selected Type',
                0
            )->addOption(
                'offset',
                null,
                InputOption::VALUE_OPTIONAL,
                'Offset For selected Type',
                0
            )->addOption(
                'type',
                null,
                InputOption::VALUE_OPTIONAL,
                'Type of document you want to populate. You must to have configure it before use',
                null
            )->addOption(
                'threads',
                null,
                InputOption::VALUE_OPTIONAL,
                'number of simultaneous threads',
                null
            )->addOption(
                'reset',
                null
            )->addOption(
                'batch',
                null,
                InputOption::VALUE_OPTIONAL,
                'Number of Document per batch',
                null
            )
        ;
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->threads                              = $input->getOption('threads') ?: 2;
        $this->limit                                = $input->getOption('limit') ?: null;
        $this->offset                               = $input->getOption('offset') ?: 0;
        $this->type                                 = $input->getOption('type');
        $this->batch                                = $input->getOption('batch');
        $this->reset                                = $input->getOption('reset');
        $this->output                               = $output;
        $this->elasticaHelper                       = $this->getContainer()->get('headoo.elasticsearch.helper');
        $this->mappings                             = $this->getContainer()->getParameter('elastica_mappings');
        $this->em                                   = $this->getContainer()->get('doctrine.orm.entity_manager');

        foreach ($this->mappings as $key=>$mapping){
            $this->aTypes[] = $key;
        }

        // We add a limit per batch which equal of the batch option
        if($input->getOption('batch')){
            $this->limit = $this->batch ;
        }

        if($input->getOption('type')){
            $this->_switchType($this->type, $this->batch);
            return 0;
        }

        foreach ($this->aTypes as $type){
            $this->_switchType($type, $this->batch);
        }

        return 0;
    }

    /**
     * @param $type
     * @param $batch
     */
    private function _switchType($type, $batch)
    {
        if(in_array($type, $this->aTypes)){
            $this->output->writeln("********************** BEGIN {$type} ************************");
            if($this->reset){
                $this->output->writeln("********************** RESET INDEX ***********************");
                $index_name = $this->getContainer()->get('headoo.elasticsearch.handler')->getIndexName($type);
                $connection = $this->mappings[$type]['connection'];
                $index      = $this->mappings[$type]['index'];
                $this->elasticaHelper->getClient($connection)->getIndex($index_name)->create($index, true);
            }
            if(!$batch){
                $this->processBatch($type,$this->getContainer()->get($this->mappings[$type]['transformer']));
            }else{
                $this->beginBatch($type);
            }
            $this->output->writeln("********************** FINISH {$type} ***********************");
            return;
        }

        $this->output->writeln("********************** Wrong Type ***********************");
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
     */
    public function runParallel(ProgressBar $progressBar, array $processes, $maxParallel, $poll = 1000)
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

        do {
            // wait for the given time
            usleep($poll);
            // remove all finished processes from the stack
            foreach ($currentProcesses as $index => $process) {
                if (!$process->isRunning()) {
                    unset($currentProcesses[$index]);
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
        $numberObjects = $this->em->createQuery("SELECT COUNT(u) FROM {$this->mappings[$type]['class']} u")->getResult()[0][1];
        $aProcess = [];
        $total    =  floor(($numberObjects - $this->offset) / $this->limit);
        $progressBar = new ProgressBar($this->output,$numberObjects - $this->offset);

        for ($i = 0; $i <= $total; $i++) {
            $_offset = $this->offset + ($this->limit * $i);
            $process = new Process("php app/console headoo:elastic:populate --type={$type} --limit={$this->limit} --offset={$_offset}");
            $aProcess[] = $process;
        }

        $max_parallel_processes = $this->threads;
        $polling_interval = 1000; // microseconds
        $this->runParallel($progressBar,$aProcess, $max_parallel_processes, $polling_interval);

        return;
    }

    /**
     * @param $type
     * @param $transformer
     */
    public function processBatch($type, $transformer)
    {
        $this->output->writeln("********************** Creating Type {$type} and Mapping ***********************");
        $index_name         = $this->getContainer()->get('headoo.elasticsearch.handler')->getIndexName($type);
        $connection         = $this->mappings[$type]['connection'];
        $index              = $this->elasticaHelper->getClient($connection)->getIndex($index_name);
        $objectType         = $index->getType($type);

        $this->_mappingFields($objectType, $this->mappings[$type]['mapping']);

        $this->output->writeln("********************** Finish Type {$type} and Mapping ***********************");
        $this->output->writeln("********************** Start populate {$type} ***********************");


        $iResults = $this->em->createQuery("SELECT COUNT(u) FROM {$this->mappings[$type]['class']} u")->getResult()[0][1];

        $q = $this->em->createQuery("select u from {$this->mappings[$type]['class']} u");

        if($this->offset){
            $q->setFirstResult($this->offset);
            $iResults = $iResults - $this->offset;
        }

        if($this->limit){
            $q->setMaxResults($this->limit);
            $iResults = $this->limit;

        }

        $iterableResult = $q->iterate();

        $progress = new ProgressBar($this->output, $iResults);
        $progress->start();

        $aDocuments = [];

        foreach ($iterableResult as $row){
            $document = $transformer->transform($row[0]);

            if (!$document) {
                continue;
            }

            $aDocuments[]= $document;
            $this->em->detach($row[0]);
            $progress->advance();
        }

        $this->_bulk($objectType,$aDocuments);
        $this->output->writeln("********************** Start populate {$type} ***********************");

        $progress->finish();
        $this->output->writeln('');
        $this->output->writeln("<info>********************** Finish populate {$type} ***********************</info>");
    }

}
