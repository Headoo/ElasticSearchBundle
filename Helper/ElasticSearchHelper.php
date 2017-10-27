<?php

namespace Headoo\ElasticSearchBundle\Helper;

use Elastica\Client;

class ElasticSearchHelper
{
    private $elasticaConfig;

    /**
     * ElasticSearchHelper constructor.
     * @param $elasticaConfig
     */
    public function __construct($elasticaConfig)
    {
        $this->elasticaConfig = $elasticaConfig;
    }

    /**
     * @param string $connectionName
     * @return \Elastica\Client
     */
    public function getClient($connectionName)
    {
        $elasticaClient = new Client([
            'host' => $this->elasticaConfig[$connectionName]['host'],
            'port' => $this->elasticaConfig[$connectionName]['port']
        ]);

        return $elasticaClient;
    }

    /**
     * @param array $servers
     * @return \Elastica\Client
     */
    static public function getCluster(array $servers)
    {
        $cluster = new Client([
            'servers' => [$servers]
        ]);

        return $cluster;
    }

    /**
     * @param Client $elasticaClient
     * @return bool
     */
    static public function isConnected(\Elastica\Client $elasticaClient)
    {
        $status = $elasticaClient->getStatus();
        $status->refresh();

        return true;
    }
}
