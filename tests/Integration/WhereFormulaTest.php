<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Review;
use Doctrine\Persistence\Proxy;

final class WhereFormulaTest extends OrmTestCase
{
    public function testDqlWhere(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);         // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);                      // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                               // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);               // orderCount=2
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [30.00, 40.00, 50.00, 60.00]); // orderCount=4

        /** @var Product[] $products */
        $products = $this->em->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.orderCount >= :orderCountFrom AND p.orderCount <= :orderCountTo')
            ->setParameter('orderCountFrom', 2)
            ->setParameter('orderCountTo', 3)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        // The field values are correct
        self::assertSame('Product 1', $products[0]->name);
        self::assertSame('Product 4', $products[1]->name);

        self::assertSame(3, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
    }

    public function testFindWhere(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 35.00]);       // orderCount=2
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [40.00, 45.00]);       // orderCount=2

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(['orderCount' => 2]);

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        // The field values are correct
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame('Product 5', $products[1]->name);

        self::assertSame(2, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
    }

    public function testDqlJoinWhere(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 2'), [20.00]);              // orderCount=1
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 3'));                       // orderCount=0
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product 4'), [25.00, 35.00]);       // orderCount=2

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

    public function testDqlWhereBetween(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.orderCount BETWEEN :min AND :max ORDER BY p.orderCount ASC'
        )
            ->setParameter('min', 1)
            ->setParameter('max', 2)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(1, $products[0]->orderCount);

        self::assertSame('Product 4', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);
    }

    public function testDqlWhereIn(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.orderCount IN (:counts) ORDER BY p.orderCount ASC'
        )
            ->setParameter('counts', [1, 3])
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(1, $products[0]->orderCount);

        self::assertSame('Product 1', $products[1]->name);
        self::assertSame(3, $products[1]->orderCount);
    }

    public function testDqlWhereInSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);  // max price=10
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]); // max price=30
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                 // no order items
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [50.00, 60.00]); // max price=60

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p ' .
            'WHERE p.id IN (' .
            '    SELECT IDENTITY(oi.product) FROM ' . OrderItem::class . ' oi WHERE oi.price > :minPrice' .
            ') ' .
            'ORDER BY p.totalRevenue ASC'
        )
            ->setParameter('minPrice', 25.00)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'totalRevenue')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Product 2 (has price 30 > 25) and Product 4 (has price 50, 60 > 25) match
        self::assertCount(2, $products);

        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(50.0, $products[0]->totalRevenue);

        self::assertSame('Product 4', $products[1]->name);
        self::assertSame(110.0, $products[1]->totalRevenue);
    }

    public function testFindWhereIn(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            ['orderCount' => [1, 3]],
            ['orderCount' => 'ASC'],
        );

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(1, $products[0]->orderCount);

        self::assertSame('Product 1', $products[1]->name);
        self::assertSame(3, $products[1]->orderCount);
    }

    public function testDqlWhereNotIn(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.orderCount NOT IN (:counts) ORDER BY p.orderCount ASC'
        )
            ->setParameter('counts', [1, 3])
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 3', $products[0]->name);
        self::assertSame(0, $products[0]->orderCount);

        self::assertSame('Product 4', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);
    }

    public function testDqlWhereNotInSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);  // orderCount=2, maxItemPrice=10
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]); // orderCount=2, maxItemPrice=30
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                 // orderCount=0, maxItemPrice=null
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [50.00, 60.00]); // orderCount=2, maxItemPrice=60

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p ' .
            'WHERE p.id NOT IN (' .
            '    SELECT IDENTITY(oi.product) FROM ' . OrderItem::class . ' oi WHERE oi.price > :minPrice' .
            ') ' .
            'ORDER BY p.name ASC'
        )
            ->setParameter('minPrice', 25.00)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'totalRevenue')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Product 1 (max price=10) and Product 3 (no items) do NOT have price > 25
        self::assertCount(2, $products);

        self::assertSame('Product 1', $products[0]->name);
        self::assertSame(15.0, $products[0]->totalRevenue);

        self::assertSame('Product 3', $products[1]->name);
        self::assertSame(0.0, $products[1]->totalRevenue);
    }

    public function testDqlWhereIsNull(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]); // orderCount=2, maxItemPrice=10
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);       // orderCount=1, maxItemPrice=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                // orderCount=0, maxItemPrice=null
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                // orderCount=0, maxItemPrice=null

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.maxItemPrice IS NULL ORDER BY p.name ASC'
        )
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'maxItemPrice')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 3', $products[0]->name);
        self::assertNull($products[0]->maxItemPrice);

        self::assertSame('Product 4', $products[1]->name);
        self::assertNull($products[1]->maxItemPrice);
    }

    public function testFindWhereIsNull(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]); // orderCount=2, maxItemPrice=10
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);       // orderCount=1, maxItemPrice=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                // orderCount=0, maxItemPrice=null
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                // orderCount=0, maxItemPrice=null

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            ['maxItemPrice' => null],
            ['name' => 'ASC'],
        );

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'maxItemPrice')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 3', $products[0]->name);
        self::assertNull($products[0]->maxItemPrice);

        self::assertSame('Product 4', $products[1]->name);
        self::assertNull($products[1]->maxItemPrice);
    }

    public function testDqlWhereIsNotNull(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]); // orderCount=2, maxItemPrice=10
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);       // orderCount=1, maxItemPrice=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                // orderCount=0, maxItemPrice=null
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                // orderCount=0, maxItemPrice=null

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.maxItemPrice IS NOT NULL ORDER BY p.maxItemPrice ASC'
        )
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'maxItemPrice')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 1', $products[0]->name);
        self::assertSame(10.0, $products[0]->maxItemPrice);

        self::assertSame('Product 2', $products[1]->name);
        self::assertSame(20.0, $products[1]->maxItemPrice);
    }

    public function testDqlWhereExists(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p ' .
            'WHERE EXISTS (SELECT 1 FROM ' . Product::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > :min) ' .
            'ORDER BY p.orderCount ASC'
        )
            ->setParameter('min', 1)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 4', $products[0]->name);
        self::assertSame(2, $products[0]->orderCount);

        self::assertSame('Product 1', $products[1]->name);
        self::assertSame(3, $products[1]->orderCount);
    }

    public function testDqlWhereNotExists(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p ' .
            'WHERE NOT EXISTS (SELECT 1 FROM ' . Product::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > :min) ' .
            'ORDER BY p.orderCount ASC'
        )
            ->setParameter('min', 1)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 3', $products[0]->name);
        self::assertSame(0, $products[0]->orderCount);

        self::assertSame('Product 2', $products[1]->name);
        self::assertSame(1, $products[1]->orderCount);
    }

    public function testDqlWhereEqualsScalarSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [25.00, 35.00]);       // orderCount=2, totalRevenue=60

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p ' .
            'WHERE p.totalRevenue = (' .
            '    SELECT MAX(p2.totalRevenue) FROM ' . Product::class . ' p2' .
            ') ' .
            'ORDER BY p.name ASC'
        )
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'totalRevenue')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Formula appears twice: once in WHERE left-hand side, once in subquery MAX()
        self::assertSame(2, substr_count($mainSql, $subSql));

        // Product 4 and Product 5 both have the maximum totalRevenue=60
        self::assertCount(2, $products);

        self::assertSame('Product 4', $products[0]->name);
        self::assertSame(60.0, $products[0]->totalRevenue);

        self::assertSame('Product 5', $products[1]->name);
        self::assertSame(60.0, $products[1]->totalRevenue);
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
