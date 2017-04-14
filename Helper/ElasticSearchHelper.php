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
     * @return \Elastica\Client
     */
    public function getClient($connectionName){
        $elasticaClient = new Client(array(
            'host' => $this->elasticaConfig[$connectionName]['host'],
            'port' => $this->elasticaConfig[$connectionName]['port']
        ));

        return $elasticaClient;
    }

    /**
     * @return \Elastica\Client
     */
    public function getCluster(array $servers){
        $cluster = new Client(array(
            'servers' => array(
                $servers
            )
        ));

        return $cluster;
    }
}
