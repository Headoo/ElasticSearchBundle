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

    /** @var \Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper */
    private $elasticSearchHelper;

    /** @var EntityManager */
    private $entityManager;

    /** @var Application */
    protected $application;


    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        parent::setUp();
        self::bootKernel();

        $this->entityManager = static::$kernel->getContainer()->get('doctrine')->getManager();
        $this->elasticSearchHelper = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');
        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);

        $this->loadFixtures();
    }

    public function testCommand1()
    {
        $options1 = [
            'command' => 'headoo:elastic:populate',
            '--reset' => true,
            '--env'   => 'prod'
        ];

        $this->application->run(new ArrayInput($options1));
        $search = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(100, count($resultSet->getResults()));
    }


    public function testCommand2()
    {
        $options2 = [
            'command' => 'headoo:elastic:populate',
            '--reset' => true,
            '--limit' => 10,
        ];

        $this->application->run(new ArrayInput($options2));
        $search = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(10, count($resultSet->getResults()));
    }

    public function testCommand3()
    {
        $options3 = [
            'command'  => 'headoo:elastic:populate',
            '--reset'  => true,
            '--offset' => 10,
        ];

        $this->application->run(new ArrayInput($options3));
        $search = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(90, count($resultSet->getResults()));
    }

    public function testCommand4()
    {
        $options4 = [
            'command' => 'headoo:elastic:populate',
            '--reset' => true,
            '--type'  => 'FakeEntity'
        ];

        $this->application->run(new ArrayInput($options4));
        $search     = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);
        $this->assertEquals(100 , count($resultSet->getResults()));
    }

    public function testCommandRunParallel()
    {
        $optionsRunParallel = [
            'command' => 'headoo:elastic:populate',
            '--reset' => true,
            '--type'  => 'FakeEntity',
            '--batch' => '4',
        ];

        $this->application->run(new ArrayInput($optionsRunParallel));
        $search     = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $query->setSize(1000);
        $resultSet = $search->search($query);

        $this->assertGreaterThan(-1, count($resultSet->getResults()));
    }

    public function testCommandWrongType()
    {
        $options1 = [
            'command'  => 'headoo:elastic:populate',
            '--type'   => 'UnknownType',
        ];

        $returnValue = $this->application->run(new ArrayInput($options1));

        self::assertNotEquals(0, $returnValue, 'This command should failed: UNKNOWN TYPE');
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

}
