<?php

namespace Headoo\ElasticSearchBundle\Event;

use Symfony\Component\EventDispatcher\Event;

class ElasticSearchEvent extends Event
{
    /** @var  string */
    protected $action;
    protected $entity;

    public function __construct($action, $entity)
    {
        $this->action          = $action;
        $this->entity          = $entity;
    }

    public function getAction()
    {
        return $this->action;
    }


    public function getEntity()
    {
        return $this->entity;
    }

}
