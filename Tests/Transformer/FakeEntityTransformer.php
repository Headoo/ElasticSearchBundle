<?php
namespace Headoo\ElasticSearchBundle\Tests\Transformer;
use Elastica\Document;
use Headoo\ElasticSearchBundle\Tests\Entity\FakeEntity;

class FakeEntityTransformer
{
    /**
     * @param FakeEntity $fake
     * @return Document
     */
    public function transform(FakeEntity $fake)
    {
        $identifier             = $fake->getId();
        $values = array(
            'name'              => $fake->getName(),
            'date'              => (new \DateTime('now'))->format('c'),
        );

        //Create a document to index
        $document = new Document($identifier, $values);
        return $document;
    }
}