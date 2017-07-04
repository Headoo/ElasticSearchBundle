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
    public function getCluster(array $servers)
    {
        $cluster = new Client([
            'servers' => [$servers]
        ]);

        return $cluster;
    }

}
