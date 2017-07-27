<?php

namespace Headoo\ElasticSearchBundle\Tests\Handler;

use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManager;
use Elastica\Query;
use Elastica\Search;
use Headoo\ElasticSearchBundle\Tests\DataFixtures\LoadData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

class ElasticSearchHandlerTest extends KernelTestCase
{
    /**
     * @var \Headoo\ElasticSearchBundle\Handler\ElasticSearchHandler
     */
    private $elasticSearchHandler;

    /**
     * @var \Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper
     */
    private $elasticSearchHelper;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var Application
     */
    protected $application;


    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager           = static::$kernel->getContainer()->get('doctrine')->getManager();
        $this->elasticSearchHandler    = static::$kernel->getContainer()->get('headoo.elasticsearch.handler');
        $this->elasticSearchHelper     = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);

        $this->loadFixtures();

    }

    public function testSendToElastic()
    {
        $fake = $this->getFakeEntity();
        $this->elasticSearchHandler->sendToElastic($fake, 'elastic.fakeentity.transformer', 'localhost', 'test');

        $search     = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $boolQuery  = new Query\BoolQuery();
        $queryMatch = new Query\Match();
        $queryMatch->setFieldQuery('_id', 1);

        $boolQuery->addMust($queryMatch);
        $query->setQuery($boolQuery);

        $query->setSize(1);
        $resultSet = $search->search($query);

        self::assertEquals('My test 1' , $resultSet->getResults()[0]->getSource()["name"]);
    }

    public function testRemoveFromElastic()
    {
        $fake = $this->getFakeEntity();
        $this->elasticSearchHandler->removeFromElastic($fake, 'localhost', 'test');

        $search     = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $boolQuery  = new Query\BoolQuery();
        $queryMatch = new Query\Match();
        $queryMatch->setFieldQuery('_id', 1);

        $boolQuery->addMust($queryMatch);
        $query->setQuery($boolQuery);

        $query->setSize(1);
        $resultSet = $search->search($query);

        self::assertEquals(0 , count($resultSet->getResults()));
    }


    public function loadFixtures(array $options = [])
    {
        # Do not show output
        self::setOutputCallback(function() {});

        $options['command'] = 'doctrine:database:create';
        $this->application->run(new ArrayInput($options));

        $options['command'] = 'doctrine:schema:create';
        $this->application->run(new ArrayInput($options));

        $loader = new Loader();
        $loader->addFixture(new LoadData());

        $purger = new ORMPurger($this->entityManager);
        $executor = new ORMExecutor($this->entityManager, $purger);
        $executor->execute($loader->getFixtures());
    }

    private function getFakeEntity()
    {
        return $this->entityManager->getRepository('\Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity')->find(1);
    }

}
