<?php

namespace Headoo\ElasticSearchBundle\Command;

use Elastica\Query;
use Elastica\ResultSet;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExodusElasticCommand extends AbstractCommand
{
    protected $nbrDocumentsFound = 0;
    protected $counterDocumentTested = 0;
    protected $counterEntitiesRemoved = 0;

    /** @var int */
    protected $batch = 100;

    protected function configure()
    {
        $this
            ->setName('headoo:elastic:exodus')
            ->setDescription('Remove not linked entities from Elastic Search')
            ->addOption('limit',   'l', InputOption::VALUE_OPTIONAL, 'Limit For selected Type', 0)
            ->addOption('offset',  'o', InputOption::VALUE_OPTIONAL, 'Offset For selected Type', 0)
            ->addOption('type',    't', InputOption::VALUE_OPTIONAL, 'Type of document you want to exodus. You must to have configure it before use', null)
            ->addOption('batch',   'b', InputOption::VALUE_OPTIONAL, 'Number of Documents per batch', null)
            ->addOption('dry-run', 'd', InputOption::VALUE_OPTIONAL, 'Dry run: do not make any change on ES', false);
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->init($input, $output);

        $this->output->writeln("<info>" . $this->completeLine($this->getName() . " " . date('H:i:s')) . "</info>");

        if ($this->verbose) {
            $sMsg = ($this->type) ? "Type: {$this->type}\n" : "";
            $sMsg .= "Offset: {$this->offset}\nBatch: {$this->batch}";
            $this->output->writeln($sMsg);
        }

        if ($this->type) {
            return $this->_switchType($this->type);
        }

        $returnValue = AbstractCommand::EXIT_SUCCESS;

        foreach ($this->aTypes as $type) {
            $result = $this->_switchType($type);
            $returnValue = ($result == AbstractCommand::EXIT_SUCCESS) ?
                $returnValue :
                $result;
        }

        return $returnValue;
    }

    /**
     * @param $type
     * @return int
     */
    private function _switchType($type)
    {
        if (!in_array($type, $this->aTypes)) {
            $this->output->writeln($this->completeLine("Wrong Type"));
            return AbstractCommand::EXIT_FAILED;
        }

        return $this->processBatch($type);
    }

    /**
     * @param \Elastica\Type $type
     * @param \Doctrine\ORM\EntityRepository $repository
     * @param ResultSet $resultSet
     */
    private function removeFromElasticSearch($type, $repository, $resultSet)
    {
        foreach ($resultSet as $result) {
            $this->counterDocumentTested++;
            $documentId = $result->getDocument()->getId();

            # Look up into Doctrine the associated Entity with the given document
            $entity = $repository->find($documentId);
            $this->entityManager->clear();

            if (!is_null($entity)) {
                continue;
            }

            if ($this->verbose) {
                $this->output->writeln(self::CLEAR_LINE . "Entity not found: {$documentId}");
            }

            # Remove document from ElasticSearch
            $this->counterEntitiesRemoved++;
            $response = $type->deleteById($documentId);

            if ($response->hasError()&& $this->verbose) {
                $this->output->writeln(self::CLEAR_LINE . "\tError: {$response->getError()}");
            }

            gc_collect_cycles();
        }

    }

    /**
     * @param string $sType
     * @return int
     */
    private function countAllResult($sType)
    {
        $index = $this->getIndexFromType($sType);
        $query = new Query();

        return $index->count($query);
    }

    /**
     * @param string $sType
     * @return int
     */
    private function processBatch($sType)
    {
        $this->output->writeln('<info>' . $this->completeLine("Start Exodus '{$sType}'") . '</info>');

        $repository = $this->getRepositoryFromType($sType);
        $index      = $this->getIndexFromType($sType);

        $from = $this->offset;
        $countTotalDocuments = $this->countAllResult($sType);

        $progressBar = $this->getProgressBar($this->output, $countTotalDocuments - $this->offset);
        $this->initCounter();

        do {
            unset($resultSet);

            $query = new Query();
            $query->setFrom($from);
            $query->setSize($this->batch);

            # Get documents from ElasticSearch
            $resultSet = $index->search($query);
            unset($query);

            $this->removeFromElasticSearch($index->getType($sType), $repository, $resultSet);

            $addMore = $this->getNextStep($this->batch, $this->offset, $from, $this->limit);
            $from += $this->batch;

            $progressBar->setMessage("$from/$countTotalDocuments");
            $progressBar->advance(count($resultSet));

        } while ((count($resultSet) > 0) && ($addMore > 0));

        $progressBar->finish();

        unset($progressBar);

        $this->output->writeln(self::CLEAR_LINE . "{$sType}: Documents tested: {$this->counterDocumentTested} Entities removed: {$this->counterEntitiesRemoved}");
        $this->output->writeln('<info>' . $this->completeLine("Finish Exodus '{$sType}'") . '</info>');

        gc_collect_cycles();

        return AbstractCommand::EXIT_SUCCESS;
    }

    /**
     * @param int $batch  Number of document to get each loop
     * @param int $offset Offset
     * @param int $from   The last query FROM
     * @param int $limit  The number of total result
     * @return mixed
     */
    private function getNextStep($batch, $offset, $from, $limit)
    {
        # No limit
        if (empty($limit) || $limit <= 0) {
            return $batch;
        }

        $resultRest = ($offset + $limit) - $from;

        return ($resultRest > $batch) ?
            $batch :
            $resultRest;
    }

    private function initCounter()
    {
        $this->counterEntitiesRemoved = 0;
        $this->counterDocumentTested = 0;
        $this->nbrDocumentsFound = 0;
    }

}
