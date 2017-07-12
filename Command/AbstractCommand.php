<?php

namespace Headoo\ElasticSearchBundle\Command;

use Doctrine\ORM\EntityManager;
use Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

abstract class AbstractCommand  extends ContainerAwareCommand
{
    const LINE_LENGTH = 70;
    const CLEAR_LINE = "\r\e[2K\r";

    const EXIT_SUCCESS = 0;
    const EXIT_FAILED = 127;

    /** @var OutputInterface */
    protected $output;
    /** @var InputInterface */
    protected $input;
    /** @var integer */
    protected $threads;
    /** @var integer */
    protected $limit;
    /** @var integer */
    protected $offset;
    /** @var bool  */
    protected $batch = false;
    /** @var string */
    protected $type;
    /** @var array */
    protected $aTypes;
    /** @var ElasticSearchHelper */
    protected $elasticSearchHelper;
    /** @var array */
    protected $mappings;
    /** @var EntityManager */
    protected $entityManager;
    /** @var bool */
    protected $reset = false;
    /** @var bool $verbose More verbose */
    protected $verbose = false;
    /** @var bool $dryRun Do not make any change on ES */
    protected $dryRun = false;
    /** @var string */
    protected $environment;

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    protected function init(InputInterface $input, OutputInterface $output)
    {
        $this->output              = $output;
        $this->input               = $input;

        $this->elasticSearchHelper = $this->getContainer()->get('headoo.elasticsearch.helper');
        $this->mappings            = $this->getContainer()->getParameter('elastica_mappings');
        $this->entityManager       = $this->getContainer()->get('doctrine.orm.entity_manager');
        $this->environment         = $this->getContainer()->get('kernel')->getEnvironment();

        $this->aTypes = array_keys($this->mappings);

        $this->readOption($input);

        if ($this->environment == 'prod') {
            $this->entityManager->getConnection()->getConfiguration()->setSQLLogger(null);
            $this->entityManager->flush();
            $this->entityManager->clear();
        }
    }

    /**
     * @param array $excludedOption
     * @return string
     */
    protected function getOptionsToString($excludedOption = [])
    {
        $aOptions = $this->input->getOptions();
        $sOptions = '';

        foreach ($aOptions as $key => $value) {
            if ($value === false || in_array($key, $excludedOption)) {
                continue;
            }

            if ($value === true) {
                $sOptions .= " --$key";
                continue;
            }

            $sOptions .= " --$key=$value";
        }

        return $sOptions;
    }

    /**
     * @param InputInterface $input
     */
    protected function readOption(InputInterface $input)
    {
        $this->limit  = $input->getOption('limit') ?: null;
        $this->offset = $input->getOption('offset') ?: 0;
        $this->type   = $input->getOption('type');
        $this->batch  = $input->getOption('batch') ?: $this->batch;

        if ($input->hasOption('reset')) {
            $this->reset   = $input->getOption('reset');
        }

        if ($input->hasOption('threads')) {
            $this->threads = $input->getOption('threads') ?: 2;
        }

        if ($input->hasOption('dry-run')) {
            $this->dryRun  = $input->getOption('dry-run');
        }

        if ($input->hasOption('verbose')) {
            $this->verbose = $input->getOption('verbose');
        }
    }

    /**
     * @param OutputInterface $output
     * @param int $max
     * @return ProgressBar
     */
    protected function getProgressBar($output, $max)
    {
        $max = ($max > 0) ? $max : 1;
        $progressBar = new ProgressBar($output, $max);

        if ($this->verbose) {
            $progressBar->setFormat('%message% Doc. %percent%% [%bar%] (%elapsed% - %estimated%) (%memory%)' . "\r");
        } else {
            $progressBar->setFormat('%message% %percent%% [%bar%] (%elapsed% - %estimated%)' . "\r");
        }

        $progressBar->setMessage('');
        $progressBar->start();

        return $progressBar;
    }

    /**
     * @param $msg
     * @return string
     */
    protected function completeLine($msg)
    {
        $nbrAstrix = (self::LINE_LENGTH - strlen($msg) - 4) / 2;

        if ($nbrAstrix <= 0) {
            return $msg;
        }

        $sAstrix = str_repeat('*', $nbrAstrix);
        $sReturn = "$sAstrix  $msg  $sAstrix";

        return (strlen($sReturn) == self::LINE_LENGTH)
            ? self::CLEAR_LINE . $sReturn
            : self::CLEAR_LINE . $sReturn . '*';
    }

    /**
     * @param string $sType
     * @return \Elastica\Index
     */
    protected function getIndexFromType($sType)
    {
        $connection = $this->mappings[$sType]['connection'];

        $index_name = $this->getContainer()
            ->get('headoo.elasticsearch.handler')
            ->getIndexName($sType);

        return $this->elasticSearchHelper
            ->getClient($connection)
            ->getIndex($index_name);
    }

    /**
     * @param string $sType
     * @return \Doctrine\ORM\EntityRepository
     */
    protected function getRepositoryFromType($sType)
    {
        return $this->entityManager->getRepository($this->mappings[$sType]['class']);
    }

}
