<?php

namespace Headoo\ElasticSearchBundle\Command;

use Elastica\Query;
use Elastica\ResultSet;
use Elastica\Scroll;
use Elastica\Search;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ExodusElasticCommand extends AbstractCommand
{
    protected $nbrDocumentsFound = 0;
    protected $counterDocumentTested = 0;
    protected $counterEntitiesRemoved = 0;
    protected $nbrDone = 0;

    /** @var int */
    protected $batch = 100;

    protected function configure()
    {
        $this->setName('headoo:elastic:exodus')
            ->setDescription('Remove not linked entities from Elastic Search')
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

        $this->output->writeln("<info>" . self::completeLine($this->getName() . " " . date('H:i:s')) . "</info>");

        if ($this->verbose) {
            $sMsg = ($this->type) ? "Type: {$this->type}\n" : "";
            $sMsg .= "Batch: {$this->batch}";
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
            $this->output->writeln(self::completeLine("Wrong Type"));
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
        $this->output->writeln('<info>' . self::completeLine("Start Exodus '{$sType}'") . '</info>');

        $repository = $this->getRepositoryFromType($sType);
        $index      = $this->getIndexFromType($sType);

        $countTotalDocuments = $this->countAllResult($sType);

        $progressBar = $this->getProgressBar($this->output, $countTotalDocuments);
        $this->initCounter();

        $search = new Search($this->getClient($sType));
        $search->addIndex($index)->addType($sType);
        $search->scroll('10m');

        $scroll = new Scroll($search);

        foreach ($scroll as $scrollId => $resultSet) {
            $this->removeFromElasticSearch($index->getType($sType), $repository, $resultSet);

            $this->nbrDone += $this->batch;
            $progressBar->setMessage("{$this->nbrDone}/{$countTotalDocuments}");
            $progressBar->advance($resultSet->count());
            unset($resultSet);
        }

        $progressBar->finish();

        unset($progressBar);

        $this->output->writeln(self::CLEAR_LINE . "{$sType}: Documents tested: {$this->counterDocumentTested} Entities removed: {$this->counterEntitiesRemoved}");
        $this->output->writeln('<info>' . self::completeLine("Finish Exodus '{$sType}'") . '</info>');

        gc_collect_cycles();

        return AbstractCommand::EXIT_SUCCESS;
    }

    private function initCounter()
    {
        $this->counterEntitiesRemoved = 0;
        $this->counterDocumentTested = 0;
        $this->nbrDocumentsFound = 0;
        $this->nbrDone = 0;
    }

}
