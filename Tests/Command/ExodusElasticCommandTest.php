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

    public function testCommand1()
    {
        $options1 = [
            'command'  => 'headoo:elastic:exodus',
            '--limit'  => 1000,
            '--batch'  => 10,
            '--offset' => 100
        ];

        $this->application->run(new ArrayInput($options1));
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
