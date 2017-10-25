<?php

namespace Headoo\ElasticSearchBundle\Tests\Helper;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ElasticSearchHelperTest extends KernelTestCase
{
    /**
     * @var \Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper
     */
    private $elasticSearchHelper;


    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        parent::setUp();
        self::bootKernel();
        $this->elasticSearchHelper     = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');

    }

    public function testClientConnection()
    {
        $connection = $this->elasticSearchHelper->getClient('localhost');
        //We test just agains elastic 5.X
        $this->assertEquals(5 , substr($connection->getVersion(),0,1));
    }

    public function testClusterConnection()
    {
        $connections = $this->elasticSearchHelper->getCluster(array(
            'host' => 'localhost',
            'port' => 9200
        ));
        $this->assertEquals(5 , substr($connections->getVersion(),0,1));
    }

}
