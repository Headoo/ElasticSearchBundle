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
    private $elasticSearchHelper;

    /**
     * @var EntityManager
     */
    private $entityManager;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

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
        $this->elasticSearchHelper     = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');
        $this->eventDispatcher         = static::$kernel->getContainer()->get('event_dispatcher');

        $this->application = new Application(self::$kernel);
        $this->application->setAutoExit(false);
        $this->loadFixtures();
    }

    public function testEventListener()
    {
        $fake = new FakeEntity();
        $fake->setName('Event Listener Test');
        $this->entityManager->persist($fake);
        $this->entityManager->flush();

        $search     = new Search($this->elasticSearchHelper->getClient('localhost'));
        $search->addIndex('test');
        $query      = new Query();
        $boolQuery  = new Query\BoolQuery();
        $queryMatch = new Query\Match();
        $queryMatch->setFieldQuery('name', 'Event Listener Test');

        $boolQuery->addMust($queryMatch);
        $query->setQuery($boolQuery);

        $query->setSize(1);
        $resultSet = $search->search($query);

        self::assertEquals('Event Listener Test', $resultSet->getResults()[0]->getSource()["name"]);
    }

    public function testEventRemove()
    {
        $fake = new FakeEntity();
        $fake->setName('Event Listener Test');
        $this->entityManager->persist($fake);
        $this->entityManager->flush();

        $event = new ElasticSearchEvent('remove', $fake);

        self::assertEquals('remove', $event->getAction());
        self::assertEquals($fake, $event->getEntity());

        $this->eventDispatcher->dispatch("headoo.elasticsearch.event", $event);
        self::assertEquals('Event Listener Test', $fake->getName());
    }

    public function testEventUpdate()
    {
        $fake = new FakeEntity();
        $fake->setName('Event Listener Test');
        $this->entityManager->persist($fake);
        $this->entityManager->flush();

        $event = new ElasticSearchEvent('update', $fake);

        self::assertEquals('update', $event->getAction());
        self::assertEquals($fake, $event->getEntity());

        $this->eventDispatcher->dispatch("headoo.elasticsearch.event", $event);
        self::assertEquals('Event Listener Test', $fake->getName());
    }

    public function testEventRemoveNoId()
    {
        $fake = new FakeEntity();
        $fake->setName('Event Listener Test');

        $event = new ElasticSearchEvent('remove', $fake);
        $this->eventDispatcher->dispatch("headoo.elasticsearch.event", $event);
        self::assertEquals('Event Listener Test', $fake->getName());
    }

    public function loadFixtures(array $options = [])
    {
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
