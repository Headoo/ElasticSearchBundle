<?php

namespace Headoo\ElasticSearchBundle\EventListener;

use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Headoo\ElasticSearchBundle\Event\ElasticSearchEvent;
use Headoo\ElasticSearchBundle\Handler\ElasticSearchHandler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class ElasticSearchListener implements EventSubscriber
{
    /** @var array  */
    protected $aTypes;

    /** @var EventDispatcherInterface  */
    protected $eventDispatcher;

    /** @var ElasticSearchHandler  */
    protected $elasticSearchHandler;

    /** @var array  */
    protected $mapping;

    /**
     * @param $eventDispatcher
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @param ElasticSearchHandler $elasticSearchHandler
     */
    public function setElasticSearchHandler(ElasticSearchHandler $elasticSearchHandler)
    {
        $this->elasticSearchHandler = $elasticSearchHandler;
        $this->mapping = $elasticSearchHandler->getMappings();
        foreach ($this->mapping as $key=>$mapping){
            $this->aTypes[] = $key;
        }
    }

    public function getSubscribedEvents()
    {
        return [
            'postPersist',
            'postUpdate',
            'postRemove',
            'preRemove'
        ];
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postPersist(LifecycleEventArgs $args)
    {
        $this->sendEvent($args, 'persist');
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function preRemove(LifecycleEventArgs $args)
    {
        $this->sendEvent($args, 'remove');
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postRemove(LifecycleEventArgs $args)
    {
        $this->sendEvent($args, 'remove');
    }

    /**
     * @param LifecycleEventArgs $args
     */
    public function postUpdate(LifecycleEventArgs $args)
    {
        $this->sendEvent($args, 'update');
    }

    /**
     * @param LifecycleEventArgs $args
     * @param $action
     */
    public function sendEvent(LifecycleEventArgs $args, $action)
    {
        $entity = $args->getEntity();
        $a      = explode("\\",get_class($entity));
        $type   = end($a);

        if (in_array($type, $this->aTypes)) {
            if (array_key_exists('auto_event',$this->mapping[$type])) {
                $this->_catchEvent(
                    $entity,
                    $this->mapping[$type]['transformer'],
                    $this->mapping[$type]['connection'],
                    $this->elasticSearchHandler->getIndexName($type),
                    $action
                );
            } else {
                $event = new ElasticSearchEvent($action, $entity);
                $this->eventDispatcher->dispatch("headoo.elasticsearch.event", $event);
            }
        }
    }

    /**
     * @param $entity
     * @param $transformer
     * @param $connectionName
     * @param $indexName
     * @param $action
     */
    private function _catchEvent($entity, $transformer, $connectionName, $indexName, $action)
    {
        if (($action == 'persist' || $action == 'update') && !$this->isSoftDeleted($entity)) {
            $this->elasticSearchHandler->sendToElastic($entity, $transformer, $connectionName, $indexName);
        } else {
            $this->elasticSearchHandler->removeFromElastic($entity, $connectionName, $indexName);
        }
    }

    /**
     * @param $entity
     * @return bool
     */
    private function isSoftDeleted($entity)
    {
        return (method_exists($entity, 'getDeletedAt') && $entity->getDeletedAt() != null);
    }

}
