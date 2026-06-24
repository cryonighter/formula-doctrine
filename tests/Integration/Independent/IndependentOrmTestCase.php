<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent;

use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\OrmTestCase;

class IndependentOrmTestCase extends OrmTestCase
{
    /**
     * Helper method to create a product entity
     */
    protected function makeProduct(string $name): Product
    {
        $product = new Product();
        $product->name = $name;

        return $product;
    }

    /**
     * Helper method to create an order item entity
     */
    protected function makeOrderItem(Product $product, float $price): OrderItem
    {
        $item = new OrderItem();
        $item->product = $product;
        $item->price = (string) $price;
        $item->quantity = 1;

        return $item;
    }

    /**
     * Helper method to persist product and order items for him
     */
    protected function createProductWithOrderItems(Product $product, array $prices = []): int
    {
        $this->em->persist($product);
        $this->em->persist(new Rating($product));

        foreach ($prices as $price) {
            $this->em->persist($this->makeOrderItem($product, $price));
        }

        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $product->id;
    }

    /**
     * Helper method for load a product by ID via DQL
     */
    protected function getProduct(int $id): Product
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(Product::class, $product);

        return $product;
    }
}
