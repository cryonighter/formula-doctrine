<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\FormulaJoinedProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\JoinedProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\Review;
use Cryonighter\FormulaDoctrine\Tests\Integration\OrmTestCase;

class JoinedInheritedOrmTestCase extends OrmTestCase
{
    /**
     * Helper method to create a product entity
     */
    protected function makeProduct(string $name): FormulaJoinedProduct
    {
        $product = new FormulaJoinedProduct();
        $product->name = $name;

        return $product;
    }

    /**
     * Helper method to create an order item entity
     */
    protected function makeOrderItem(JoinedProduct $product, float $price): OrderItem
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
        $product = $this->em->find(JoinedProduct::class, $productId);

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
    protected function createProductWithOrderItems(JoinedProduct $product, array $prices = []): int
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
        $product = $this->em->find(JoinedProduct::class, $productId);

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
    protected function getProduct(int $id): FormulaJoinedProduct
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(FormulaJoinedProduct::class, $product);

        return $product;
    }
}
