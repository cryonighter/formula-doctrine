<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Review;
use Doctrine\Persistence\Proxy;

final class WhereFormulaTest extends OrmTestCase
{
    public function testDqlWhere(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);

        /** @var Product[] $products */
        $products = $this->em->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.orderCount >= :orderCount')
            ->setParameter('orderCount', 2)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of reviews
        self::assertCount(2, $products);

        // The field values are correct
        self::assertSame('Product 1', $products[0]->name);
        self::assertSame('Product 4', $products[1]->name);

        self::assertSame(3, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
    }

    public function testFindWhere(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 35.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [40.00, 45.00]);

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(['orderCount' => 2]);

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of reviews
        self::assertCount(2, $products);

        // The field values are correct
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame('Product 5', $products[1]->name);

        self::assertSame(2, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
    }

    public function testDqlJoinWhere(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 1'), [5.00, 10.00, 15.00]);
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 2'), [20.00]);
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 3'));
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 4'), [25.00, 35.00]);

        $this->createReview('Test review 1', $productId1);
        $this->createReview('Test review 2', $productId2);
        $this->createReview('Test review 3', $productId3);
        $this->createReview('Test review 4', $productId4);

        /** @var Review $found */
        $reviews = $this->em->createQuery('SELECT r FROM ' . Review::class . ' r JOIN r.product p WHERE p.orderCount >= :orderCount')
            ->setParameter('orderCount', 2)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of reviews
        self::assertCount(2, $reviews);

        // The field values are correct
        self::assertSame('Test review 1', $reviews[0]->description);
        self::assertSame('Test review 4', $reviews[1]->description);

        // Verify that product is a Doctrine proxy (not yet loaded)
        self::assertInstanceOf(Proxy::class, $reviews[0]->product);
        self::assertInstanceOf(Proxy::class, $reviews[1]->product);
    }

    /**
     * Helper method to create a review and return its ID
     */
    private function createReview(string $description, int $productId): int
    {
        // To simplify debugging SqlWalker, it is better to use the find() function
        $product = $this->em->find(Product::class, $productId);

        $review = new Review();
        $review->product = $product;
        $review->rating = rand(1, 5);
        $review->description = $description;

        $this->em->persist($review);
        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $review->id;
    }
}
