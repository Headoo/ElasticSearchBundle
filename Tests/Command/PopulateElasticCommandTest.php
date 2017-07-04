<?php

namespace Headoo\ElasticSearchBundle\Tests\Command;

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

class PopulateElasticCommandTest extends KernelTestCase
{

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


        $this->_em = static::$kernel->getContainer()->get('doctrine')->getManager();
        $this->_elasticSearchHelper = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');
        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);

        $this->loadFixtures();
    }

    public function testCommand1()
    {
        $options1['command'] = 'headoo:elastic:populate';
        $options1['--reset'] = true;
        $this->application->run(new ArrayInput($options1));
        $search = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(100, count($resultSet->getResults()));
    }


    public function testCommand2()
    {
        $options2['command'] = 'headoo:elastic:populate';
        $options2['--reset'] = true;
        $options2['--limit'] = 10;
        $this->application->run(new ArrayInput($options2));
        $search = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(10, count($resultSet->getResults()));
    }

    public function testCommand3()
    {
        $options3['command'] = 'headoo:elastic:populate';
        $options3['--reset'] = true;
        $options3['--offset'] = 10;
        $this->application->run(new ArrayInput($options3));
        $search = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(90, count($resultSet->getResults()));
    }

    public function testCommand4()
    {
        $options4['command'] = 'headoo:elastic:populate';
        $options4['--reset'] = true;
        $options4['--type'] = 'FakeEntity';
        $this->application->run(new ArrayInput($options4));
        $search     = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(100 , count($resultSet->getResults()));
    }

    public function testCommandRunParallel()
    {
        $optionsRunParallel['command'] = 'headoo:elastic:populate';
        $optionsRunParallel['--reset'] = true;
        $optionsRunParallel['--type'] = 'FakeEntity';
        $optionsRunParallel['--batch'] = '4';
        $this->application->run(new ArrayInput($optionsRunParallel));
        $search     = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(100 , count($resultSet->getResults()));
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
