<?php

namespace Headoo\ElasticSearchBundle\Tests\Handler;


use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManager;
use Elastica\Query;
use Elastica\Search;
use Headoo\ElasticSearchBundle\Event\ElasticSearchEvent;
use Headoo\ElasticSearchBundle\Tests\DataFixtures\LoadData;
use Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ElasticSearchEventListenerTest extends KernelTestCase
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
     * @var EventDispatcherInterface
     */
    private $_eventDispatcher;

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
        $this->_elasticSearchHelper     = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');
        $this->_eventDispatcher         = static::$kernel->getContainer()->get('event_dispatcher');

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
        $this->loadFixtures();
    }

    public function testEventListener()
    {
        $fake = new FakeEntity();
        $fake->setName('Event Listener Test');
        $this->_em->persist($fake);
        $this->_em->flush();

        $search     = new Search($this->_elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $boolQuery  = new Query\BoolQuery();
        $queryMatch = new Query\Match();
        $queryMatch->setFieldQuery('name', 'Event Listener Test');

        $boolQuery->addMust($queryMatch);
        $query->setQuery($boolQuery);

        $query->setSize(1);
        $resultSet = $search->search($query);
        $this->assertEquals('Event Listener Test', $resultSet->getResults()[0]->getSource()["name"]);
    }

    public function testEventRemove()
    {
        $fake = new FakeEntity();
        $fake->setName('Event Listener Test');
        $this->_em->persist($fake);
        $this->_em->flush();

        $event = new ElasticSearchEvent('remove', $fake);
        $this->_eventDispatcher->dispatch("headoo.elasticsearch.event", $event);
        $this->assertEquals('Event Listener Test', $fake->getName());
    }

    public function testEventRemoveNoId()
    {
        $fake = new FakeEntity();
        $fake->setName('Event Listener Test');

        $event = new ElasticSearchEvent('remove', $fake);
        $this->_eventDispatcher->dispatch("headoo.elasticsearch.event", $event);
        $this->assertEquals('Event Listener Test', $fake->getName());
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
