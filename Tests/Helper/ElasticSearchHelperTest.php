<?php

namespace Headoo\ElasticSearchBundle\Tests\Helper;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class ElasticSearchHelperTest extends KernelTestCase
{
    /** @var \Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper */
    private $elasticSearchHelper;

    /**
     * {@inheritDoc}
     */
    public function setUp()
    {
        parent::setUp();
        self::bootKernel();
        $this->elasticSearchHelper = static::$kernel->getContainer()->get('headoo.elasticsearch.helper');
    }

    public function testClientConnection()
    {
        $connection = $this->elasticSearchHelper->getClient('localhost');
        //We test just against elastic 5.X
        $this->assertEquals(
            5,
            substr($connection->getVersion(), 0, 1),
            'Expected ElasticSearch version 5.x.x'
        );
    }

    public function testClusterConnection()
    {
        $connections = $this->elasticSearchHelper->getCluster(['host' => 'localhost', 'port' => 9200]);

        $this->assertEquals(
            5,
            substr($connections->getVersion(), 0, 1),
            'Expected ElasticSearch version 5.x.x'
        );

        $this->assertTrue(
            $this->elasticSearchHelper->isConnected($connections),
            'Expected Client correctly connected'
        );
    }

    /**
     * @expectedException \Elastica\Exception\Connection\HttpException
     */
    public function testClusterNotConnected()
    {
        $connections = $this->elasticSearchHelper->getCluster(['host' => '1.2.3.4', 'port' => 5678]);
        $this->assertNotTrue(
            $this->elasticSearchHelper->isConnected($connections),
            'Connection should failed in HttpException'
        );
    }
}
