<?php

namespace Headoo\ElasticSearchBundle\Tests\Command;

use Doctrine\Common\DataFixtures\Executor\ORMExecutor;
use Doctrine\Common\DataFixtures\Loader;
use Doctrine\Common\DataFixtures\Purger\ORMPurger;
use Doctrine\ORM\EntityManager;
use Headoo\ElasticSearchBundle\Tests\DataFixtures\LoadData;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;

class ExodusElasticCommandTest extends KernelTestCase
{

    /** @var \Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper */
    private $_elasticSearchHelper;

    /** @var EntityManager */
    private $_em;

    /** @var Application */
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

    public function testCommandFakeEntity()
    {
        $options1 = [
            'command'  => 'headoo:elastic:exodus',
            '--batch'  => 10,
            '--dry-run' => true,
            '--verbose' => true,
            '--env'     => 'prod',
        ];

        $returnValue = $this->application->run(new ArrayInput($options1));

        self::assertEquals(0, $returnValue, 'This command should succeed');
    }

    public function testCommandWrongType()
    {
        $options1 = [
            'command'  => 'headoo:elastic:exodus',
            '--batch'  => 10,
            '--type'   => 'UnknownType',
            '--dry-run' => true,
            '--verbose' => true
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

        $purger = new ORMPurger($this->_em);
        $executor = new ORMExecutor($this->_em, $purger);
        $executor->execute($loader->getFixtures());

        # Populate ES
        $options4['command'] = 'headoo:elastic:populate';
        $options4['--reset'] = true;
        $options4['--type'] = 'FakeNoAutoEventEntity';
        $this->application->run(new ArrayInput($options4));

        # Remove one entity in Doctrine
        $entity = $this->_em->getRepository('\Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity')->findOneBy([]);
        $this->_em->remove($entity);
        $this->_em->flush($entity);
    }

}
