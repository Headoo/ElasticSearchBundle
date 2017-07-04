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
    private $_elasticSearchHandler;

    /**
     * @var \Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper
     */
    private $_elasticSearchHelper;

    /**
     * @var EntityManager
     */
    private $_em;

    /**
     * @var Application
     */
    protected $application;


    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        self::bootKernel();

        $this->_em                      = static::$kernel->getContainer()->get('doctrine')->getManager();
        $this->_elasticSearchHandler    = static::$kernel->getContainer()->get('headoo.elasticsearch.handler');
        $this->_elasticSearchHelper     = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);

        $this->loadFixtures();

    }

    public function testSendToElastic()
    {
        $fake = $this->_em->getRepository('\Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity')->find(1);
        $this->_elasticSearchHandler->sendToElastic($fake, 'elastic.fakeentity.transformer', 'localhost', 'test');

        $search     = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $boolQuery  = new Query\BoolQuery();
        $queryMatch = new Query\Match();
        $queryMatch->setFieldQuery('_id', 1);

        $boolQuery->addMust($queryMatch);
        $query->setQuery($boolQuery);

        $query->setSize(1);
        $resultSet = $search->search($query);
        $this->assertEquals('My test 1' , $resultSet->getResults()[0]->getSource()["name"]);

    }

    public function testRemoveFromElastic()
    {
        $fake = $this->_em->getRepository('\Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity')->find(1);
        $this->_elasticSearchHandler->removeFromElastic($fake, 'localhost', 'test');

        $search     = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $boolQuery  = new Query\BoolQuery();
        $queryMatch = new Query\Match();
        $queryMatch->setFieldQuery('_id', 1);

        $boolQuery->addMust($queryMatch);
        $query->setQuery($boolQuery);

        $query->setSize(1);
        $resultSet = $search->search($query);
        $this->assertEquals(0 , count($resultSet->getResults()));

    }


    public function loadFixtures(array $options = [])
    {
        $options['command'] = 'doctrine:database:create';
        $this->application->run(new ArrayInput($options));

        $options['command'] = 'doctrine:schema:create';
        $this->application->run(new ArrayInput($options));

        $loader = new Loader();
        $loader->addFixture(new LoadData());

        $purger = new ORMPurger($this->_em);
        $executor = new ORMExecutor($this->_em, $purger);
        $executor->execute($loader->getFixtures());
    }

}
