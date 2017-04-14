<?php
namespace Headoo\ElasticSearchBundle\Handler;
use Headoo\ElasticSearchBundle\Helper\ElasticSearchHelper;
use Symfony\Component\DependencyInjection\Container;


/**
 * Class ElasticSearchHandler
 * @package Headoo\ElasticSearchBundle\Handler
 */
class ElasticSearchHandler
{
    /** @var  ElasticSearchHelper */
    protected $elasticSearchHelper;

    /** @var  Container */
    protected $container;

    /** @var  array */
    protected $mappings;

    /**
     * @param Container $container
     */
    public function setContainer(Container $container)
    {
        $this->container                = $container;
        $this->elasticSearchHelper      = $container->get('headoo.elasticsearch.helper');
        $this->mappings                 = $this->container->getParameter('elastica_mappings');
    }

    /**
     * @param $entity
     * @param $transformer
     * @param $connectionName
     * @param $indexName
     */
    public function sendToElastic($entity, $transformer, $connectionName,$indexName){
        $a          = explode("\\",get_class($entity));
        $type       = end($a);
        $document   = $this->container->get($transformer)->transform($entity);
        $index      = $this->elasticSearchHelper->getClient($connectionName)->getIndex($indexName);

        $index->getType($type)->addDocument($document);
        $index->refresh();
    }

    /**
     * @param $entity
     * @param $connectionName
     * @param $indexName
     */
    public function removeToElastic($entity, $connectionName,$indexName){
        $a          = explode("\\",get_class($entity));
        $type       = end($a);
        $index      = $this->elasticSearchHelper->getClient($connectionName)->getIndex($indexName);

        $index->getType($type)->deleteById($entity->getId());
        $index->refresh();
    }


    /**
     * @param $type
     * @return string
     */
    public function getIndexName($type){
        if(array_key_exists('index_name', $this->mappings[$type])){
            $index_name = $this->mappings[$type]['index_name'];
        }else{
            $index_name = strtolower($type);
        }
        return $index_name;
    }

    /**
     * @return array
     */
    public function getMappings(){
        return $this->mappings;
    }
}
