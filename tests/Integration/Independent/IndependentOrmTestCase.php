<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent;

use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Review;
use Cryonighter\FormulaDoctrine\Tests\Integration\OrmTestCase;
use DateTimeImmutable;

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
     * Helper method to create a review and return its ID
     */
    protected function createReview(int $productId, string $description, int $rating, ?DateTimeImmutable $created = null): int
    {
        // To simplify debugging SqlWalker, it is better to use the find() function
        $product = $this->em->find(Product::class, $productId);

        $review = new Review();
        $review->product = $product;
        $review->rating = $rating;
        $review->description = $description;
        $review->created = $created ?? new DateTimeImmutable();

        $this->em->persist($review);
        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $review->id;
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
