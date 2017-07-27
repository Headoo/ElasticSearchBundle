<?php

/**********************************************************************
 ************************************CONNECTIONS***********************
 **********************************************************************
 */

$connections =
    array('localhost'=>
        array(
            'host'    => 'localhost',
            'port'    => '9200',
        )
);


$container->setParameter('elastica_connections', $connections);

/**********************************************************************
 ************************************INDEX*****************************
 **********************************************************************
 */
$elasticaIndex = array(
    'number_of_shards' => 1,
    'number_of_replicas' => 1,
    'analysis' => array(
        'analyzer' => array(
            'default' => array(
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => array('lowercase')
            ),
            'default_search' => array(
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => array('standard', 'lowercase')
            ),
        ),
    )
);


$mapping['FakeEntity']['mapping'] = array(
    'id'                => array('type' => 'integer', 'include_in_all' => TRUE),
    'date'              => array('type' => 'date', 'include_in_all' => FALSE),
);

$mapping['FakeNoAutoEventEntity'] = [
    'class'         => '\Headoo\ElasticSearchBundle\Tests\Entity\FakeNoAutoEventEntity',
    'index'         => $elasticaIndex,
    'transformer'   => 'elastic.fakenoautoevententity.transformer',
    'connection'    => 'localhost',
    'index_name'    => 'test_noauto',
];

$mapping['FakeEntity']['class']         = '\Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity';
$mapping['FakeEntity']['index']         = $elasticaIndex;
$mapping['FakeEntity']['transformer']   = 'elastic.fakeentity.transformer';
$mapping['FakeEntity']['connection']    = 'localhost';
$mapping['FakeEntity']['index_name']    = 'test';
$mapping['FakeEntity']['auto_event']    = true;



$container->setParameter('elastica_mappings', $mapping);

