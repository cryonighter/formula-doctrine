<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\FormulaSingleProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\Review;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\SingleProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\OrmTestCase;

class SingleInheritedOrmTestCase extends OrmTestCase
{
    /**
     * Helper method to create a product entity
     */
    protected function makeProduct(string $name): FormulaSingleProduct
    {
        $product = new FormulaSingleProduct();
        $product->name = $name;

        return $product;
    }

    /**
     * Helper method to create an order item entity
     */
    protected function makeOrderItem(SingleProduct $product, float $price): OrderItem
    {
        $item = new OrderItem();
        $item->product = $product;
        $item->price = (string) $price;
        $item->quantity = 1;

        return $item;
    }

    /**
     * Helper method to create multiple reviews for a product
     */
    protected function createManyReviews(int $productId, array $ratings): void
    {
        // To simplify debugging SqlWalker, it is better to use the find() function
        $product = $this->em->find(SingleProduct::class, $productId);

        foreach ($ratings as $rating) {
            $review = new Review();
            $review->product = $product;
            $review->rating = $rating;
            $review->description = 'Test review';

            $this->em->persist($review);
        }

        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();
    }

    /**
     * Helper method to persist product and order items for him
     */
    protected function createProductWithOrderItems(SingleProduct $product, array $prices = []): int
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
    protected function createReview(int $productId, string $description, int $rating): int
    {
        // To simplify debugging SqlWalker, it is better to use the find() function
        $product = $this->em->find(SingleProduct::class, $productId);

        $review = new Review();
        $review->product = $product;
        $review->rating = $rating;
        $review->description = $description;

        $this->em->persist($review);
        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $review->id;
    }

    /**
     * Helper method for load a product by ID via DQL
     */
    protected function getProduct(int $id): FormulaSingleProduct
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(FormulaSingleProduct::class, $product);

        return $product;
    }
}
