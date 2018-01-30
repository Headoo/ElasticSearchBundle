<?php

/**********************************************************************
 ************************************CONNECTIONS***********************
 **********************************************************************
 */

$connections = [
    'localhost' => [
        'host' => 'localhost',
        'port' => '9200',
        'timeout' => '10',
        'connectTimeout' => '10'
    ]
];


$container->setParameter('elastica_connections', $connections);

/**********************************************************************
 ************************************INDEX*****************************
 **********************************************************************
 */
$elasticaIndex = [
    'number_of_shards' => 1,
    'number_of_replicas' => 1,
    'analysis' => [
        'analyzer' => [
            'default' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => ['lowercase']
            ],
            'default_search' => [
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => ['standard', 'lowercase']
            ],
        ],
    ]
];

$defaultMapping = [
    'id' => [
        'type' => 'integer',
        'include_in_all' => true
    ],
    'date' => [
        'type' => 'date',
        'include_in_all' => false
    ],
];

$mapping['FakeNoAutoEventEntity'] = [
    'class'         => '\Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity',
    'index'         => $elasticaIndex,
    'transformer'   => 'elastic.fakeentity.transformer',
    'connection'    => 'localhost',
    'index_name'    => 'test_no_auto_event',
    'mapping'       => $defaultMapping,
];

$mapping['FakeEntity']['class']         = '\Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity';
$mapping['FakeEntity']['index']         = $elasticaIndex;
$mapping['FakeEntity']['transformer']   = 'elastic.fakeentity.transformer';
$mapping['FakeEntity']['connection']    = 'localhost';
$mapping['FakeEntity']['index_name']    = 'test';
$mapping['FakeEntity']['auto_event']    = true;
$mapping['FakeEntity']['mapping']       = $defaultMapping;


$container->setParameter('elastica_mappings', $mapping);
