<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class UpdateAndDeleteQueryTest extends OrmTestCase
{
    /**
     * Test that DQL update query works correctly
     */
    public function testDqlUpdateQuery(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Old Name'));

        $productClassName = Product::class;

        $this->em->createQuery("UPDATE $productClassName t SET t.name = 'New Name' WHERE t.id = :productId")
            ->setParameter('productId', $productId)
            ->execute();

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        $result = $this->getProduct($productId);

        // The field values are correct
        self::assertSame('New Name', $result->name);
    }

    /**
     * Test that DQL delete query works correctly
     */
    public function testDqlDeleteQuery(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Product to Delete'));

        $productClassName = Product::class;

        $this->em->createQuery("DELETE FROM $productClassName t WHERE t.id = :productId")
            ->setParameter('productId', $productId)
            ->execute();

        // Exactly 1 query - DELETE should not trigger formula processing
        self::assertCount(1, $this->queryLogger->getQueries());

        $result = $this->em->find(Product::class, $productId);

        // Product should be deleted
        self::assertNull($result);
    }

    /**
     * Test that DQL delete query with multiple products works correctly
     */
    public function testDqlDeleteMultipleQuery(): void
    {
        $product1Id = $this->createProductWithOrderItems($this->makeProduct('Product 1'));
        $product2Id = $this->createProductWithOrderItems($this->makeProduct('Product 2'));
        $product3Id = $this->createProductWithOrderItems($this->makeProduct('Product 3'));

        $productClassName = Product::class;

        // Delete products with IDs 1 and 2
        $deleted = $this->em->createQuery("DELETE FROM $productClassName t WHERE t.id IN (:ids)")
            ->setParameter('ids', [$product1Id, $product2Id])
            ->execute();

        // Should delete exactly 2 products
        self::assertSame(2, $deleted);

        // Exactly 1 query - DELETE should not trigger formula processing
        self::assertCount(1, $this->queryLogger->getQueries());

        // Products 1 and 2 should be deleted
        self::assertNull($this->em->find(Product::class, $product1Id));
        self::assertNull($this->em->find(Product::class, $product2Id));

        // Product 3 should still exist
        self::assertNotNull($this->em->find(Product::class, $product3Id));
    }
}
