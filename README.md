ElasticSearchBundle
=========

[![Build Status](https://travis-ci.org/Headoo/ElasticSearchBundle.svg?branch=master)](https://travis-ci.org/Headoo/ElasticSearchBundle)
[![Code Climate](https://codeclimate.com/github/Headoo/ElasticSearchBundle/badges/gpa.svg)](https://codeclimate.com/github/Headoo/ElasticSearchBundle)
[![Latest Stable Version](https://poser.pugx.org/headoo/elasticsearch-bundle/v/stable)](https://packagist.org/packages/headoo/elasticsearch-bundle)
[![codecov](https://codecov.io/gh/Headoo/ElasticSearchBundle/branch/master/graph/badge.svg)](https://codecov.io/gh/Headoo/ElasticSearchBundle)

ElasticSearchBundle is a Symfony2/3 Bundle designed for simply use ElasticSearch 5.x with Doctrine 2.x

## Installation

Via Composer

``` bash
$ composer require headoo/elasticsearch-bundle
```
or in composer.json file
``` bash
"headoo/elasticsearch-bundle": "dev-master"
```

Register the bundle in `app/AppKernel.php`:

``` php
public function registerBundles()
{
    return array(
        // ...
        new Headoo\ElasticSearchBundle\HeadooElasticSearchBundle(),
        // ...
    );
}
```

Configuration
-------------

Configure your connections and mappings in `config.yml` :
And we will linked this to a PHP config. You can use what you want. I prefer this instead yml, it's more in a ElasticSearch way.


``` yaml
imports:
    - { resource: elastic.php }
headoo_elastic_search:
    connections: %elastica_connections%
    mappings: %elastica_mappings%
services:
    name.of.your.transformer.service:
        class: Name\NameBundle\Entity\Transformer\EntityTransformer
```

I give you an example of the `elastic.php`.

``` php
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
                'filter' => array('lowercase'')
            ),
            'default_search' => array(
                'type' => 'custom',
                'tokenizer' => 'standard',
                'filter' => array('standard', 'lowercase')
            ),
        ),
    )
);


$mapping['YourEntityClassName']['mapping'] = array(
    'id'                => array('type' => 'integer', 'include_in_all' => TRUE),
    'date'              => array('type' => 'date', 'include_in_all' => FALSE),
);

$mapping['YourEntityClassName']['class']         = '\Name\NameBundle\Entity\YourEntityClassName';
$mapping['YourEntityClassName']['index']         = $elasticaIndex;
$mapping['YourEntityClassName']['transformer']   = 'name.of.your.transformer.service';
$mapping['YourEntityClassName']['connection']    = 'localhost';
//Optional, if you need a customize Index Name. If you don't use it, the index will be strlower of your Entity Name
$mapping['YourEntityClassName']['index_name']    = 'indexname';
//Optional, it's use if you want to trigger automatically creation,update and deletion of entity
$mapping['YourEntityClassName']['auto_event']    = true;


$container->setParameter('elastica_mappings', $mapping);


```

Example of entity


```php
<?php

namespace Name\NameBundle\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\Entity()
 */
class YourEntityClassName
{
    /**
     * @var integer
     *
     * @ORM\Column(name="id", type="integer")
     * @ORM\Id
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    protected $id;


    /**
     * Set id
     *
     * @param integer $id
     * @return Id
     */
    public function setId($id)
    {
        $this->id = $id;

        return $this;
    }


    /**
     * @var string
     *
     * @ORM\Column(name="name", type="string", length=255)
     */
    protected $name;



    /**
     * Get id
     *
     * @return integer
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     * @return City
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Get name
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }
}

```

As you can see you have to create a Transformer for your Entities.


``` php
<?php
namespace Name\NameBundle\Entity\Transformer\EntityTransformer;
use Elastica\Document;
use Name\NameBundle\Entity\YourEntityClassName;

class YourEntityClassName
{
    /**
     * @param YourEntityClassName $yourEntityClassName
     * @return Document
     */
    public function transform(YourEntityClassName $yourEntityClassName)
    {
        $identifier             = $yourEntityClassName->getId();
        $values = array(
            'name'              => $yourEntityClassName->getName(),
            'date'              => (new \DateTime('now'))->format('c'),
        );

        //Create a document to index
        $document = new Document($identifier, $values);
        return $document;
    }
}
```



## Usage

If auto_event is set, you have nothing to do for creation, update and deletion of your entities.

You can begin to call Elastic with HeadooElasticService and Ruflin. Example in a Controller.

```php
    use Elastica\Query;
    use Elastica\Search;

    $elasticClient           = $this->container->get('headoo.elasticsearch.helper')->getClient('localhost');
    $search                  = new Search($elasticClient);
    //We will search in our index defined in mapping
    $search->addIndex('indexname');
    $query                   = new Query();
    $boolQuery               = new Query\BoolQuery();
    $queryMatch              = new Query\Match();
    $queryMatch->setFieldQuery('name', 'whatever');

    $boolQuery->addMust($queryMatch);
    $query->setQuery($boolQuery);
    $resultSet = $search->search($query);
```
For more information about querying Elastic, look at [Ruflin elastica.io](http://elastica.io)


If auto_event is not set, you can listen `headoo.elasticsearch.event` like this :
```yaml
name.elasticsearch.listener:
    class: Name\NameBundle\EventListener\ElasticSearchListener
    tags:
        - { name: kernel.event_listener, event: headoo.elasticsearch.event, method: onElasticEntityAction }
``` 

And in your EventListener Class
```php
<?php

namespace Name\NameBundle\EventListener;

use Headoo\ElasticSearchBundle\Event\ElasticSearchEvent;

class ElasticSearchListener
{
    /**
     * @param ElasticSearchEvent $event
     */
    public function onElasticEntityAction(ElasticSearchEvent $event)
    {
        //Action can be persist, update and delete
        $action = $event->getAction();
        //Your Doctrine Entity
        $entity = $event->getEntity();
    }
}
``` 

## Command for populate
###### CAREFULL: You have to set --reset flag on command FIRST TIME you populate a type or all types.
After configuration of your entities, you maybe want make them available on ElasticSearch. You have to use `php app/console headoo:elastic:populate`. Differents options are available :

* --limit=int : Limit of your collection
* --offset=int : Offset of your collection 
* --type=string : Name of your Object (in our example it's YourEntityClassName)
* --threads=int : Number of threads you want to use for. If you use it, limit will not be available, and you have to set a batch.
* --reset : For reset your indexes. BE CAREFULL, all your data will be lost in your Elastic Cluster
* --batch=int : Length of collection per threads. Use this only with threads

## Command for exodus
This command check if each document in ElasticSearch, is still linked with an entity in Doctrine.
If not, this command will remove the orphan document from ES. 
###### Reminder: Doctrine and ES should always be iso (with option 'auto_event', without that option, it can be a small delay). 
###### If this command find document not linked, ask you why!

* --limit=int : Limit of your collection
* --offset=int : Offset of your collection 
* --type=string : Name of your Object (in our example it's YourEntityClassName)
* --batch=int : Length of collection per threads. Use this only with threads
* --dry-run : Just test. Do not Remove any document from ES
* --verbose : Make more verbose the output

## Security
If you discover a security vulnerability, please email instead of using the issue tracker. All security vulnerabilities will be promptly addressed.

## Standalone Test

### How to test

1. clone repo : `$ sudo git clone https://github.com/Headoo/ElasticSearchBundle.git`
2. go into directory : `$ cd ElasticSearchBundle/`
3. install composer as explained here : https://getcomposer.org/download/
4. launch composer update : `$ ./composer.phar update`
5. launch test : `$ ./vendor/bin/phpunit`

## License
This Bundle is open-sourced software licensed under the MIT license