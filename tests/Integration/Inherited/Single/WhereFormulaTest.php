<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\FormulaSingleProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\Review;
use Doctrine\Persistence\Proxy;

final class WhereFormulaTest extends SingleInheritedOrmTestCase
{
    public function testDqlWhere(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);         // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);                      // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                               // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);               // orderCount=2
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [30.00, 40.00, 50.00, 60.00]); // orderCount=4

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery('SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.orderCount >= :orderCountFrom AND p.orderCount <= :orderCountTo')
            ->setParameter('orderCountFrom', 2)
            ->setParameter('orderCountTo', 3)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the SELECT statement
        // The WHERE statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->getRepository(FormulaSingleProduct::class)->findBy(['orderCount' => 2]);

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the SELECT statement
        // The WHERE statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

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

        $this->createReview($productId1, 'Test review 1', rand(1, 5));
        $this->createReview($productId2, 'Test review 2', rand(1, 5));
        $this->createReview($productId3, 'Test review 3', rand(1, 5));
        $this->createReview($productId4, 'Test review 4', rand(1, 5));

        /** @var Review[] $reviews */
        $reviews = $this->em->createQuery(
            'SELECT r FROM ' . Review::class . ' r ' .
            'JOIN ' . FormulaSingleProduct::class . ' p WITH r.product = p ' .
            'WHERE p.orderCount >= :orderCount'
        )
            ->setParameter('orderCount', 2)
            ->getResult();

        // 1 main query + 2 extra queries for lazy loading SingleProduct via BasicEntityPersister
        // This is expected behaviour for inheritance — Review.product is typed as
        // SingleProduct (base class), so Doctrine reloads each product separately
        self::assertCount(3, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the WHERE statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned the required amount of reviews
        self::assertCount(2, $reviews);

        // The field values are correct
        self::assertSame('Test review 1', $reviews[0]->description);
        self::assertSame('Test review 4', $reviews[1]->description);

        // Inheritance always loads eagerly or through N+1 via BasicEntityPersister
        self::assertNotInstanceOf(Proxy::class, $reviews[0]->product);
        self::assertNotInstanceOf(Proxy::class, $reviews[1]->product);
    }

    public function testDqlWhereBetween(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.orderCount BETWEEN :min AND :max ORDER BY p.orderCount ASC'
        )
            ->setParameter('min', 1)
            ->setParameter('max', 2)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.orderCount IN (:counts) ORDER BY p.orderCount ASC'
        )
            ->setParameter('counts', [1, 3])
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(1, $products[0]->orderCount);

        self::assertSame('Product 1', $products[1]->name);
        self::assertSame(3, $products[1]->orderCount);
    }

    public function testDqlWhereInSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);  // totalRevenue=15
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]); // totalRevenue=50
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                 // totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [50.00, 60.00]); // totalRevenue=110

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p ' .
            'WHERE p.id IN (' .
            '    SELECT p2.id FROM ' . FormulaSingleProduct::class . ' p2 WHERE p2.totalRevenue > :minRevenue' .
            ') ' .
            'ORDER BY p.totalRevenue ASC'
        )
            ->setParameter('minRevenue', 20)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaSingleProduct::class, 'totalRevenue');

        // The totalRevenue field formula appears twice: once in the SELECT statement, once inside IN subquery
        // The ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // Product 2 (totalRevenue=50 > 20) and Product 4 (totalRevenue=110 > 20) match
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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->getRepository(FormulaSingleProduct::class)->findBy(
            ['orderCount' => [1, 3]],
            ['orderCount' => 'ASC'],
        );

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.orderCount NOT IN (:counts) ORDER BY p.orderCount ASC'
        )
            ->setParameter('counts', [1, 3])
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned the required amount of products
        self::assertCount(2, $products);

        self::assertSame('Product 3', $products[0]->name);
        self::assertSame(0, $products[0]->orderCount);

        self::assertSame('Product 4', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);
    }

    public function testDqlWhereNotInSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);  // totalRevenue=15
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]); // totalRevenue=50
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                 // totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [50.00, 60.00]); // totalRevenue=110

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p ' .
            'WHERE p.id NOT IN (' .
            '    SELECT p2.id FROM ' . FormulaSingleProduct::class . ' p2 WHERE p2.totalRevenue > :minRevenue' .
            ') ' .
            'ORDER BY p.name ASC'
        )
            ->setParameter('minRevenue', 20)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaSingleProduct::class, 'totalRevenue');

        // The totalRevenue field formula appears twice: once in the SELECT statement, once inside NOT IN subquery
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // Product 1 (totalRevenue=15 ≤ 20) and Product 3 (totalRevenue=0 ≤ 20) do NOT match subquery
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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.maxItemPrice IS NULL ORDER BY p.name ASC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaSingleProduct::class, 'maxItemPrice');

        // The maxItemPrice field formula appears once: in the SELECT statement
        // The WHERE statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->getRepository(FormulaSingleProduct::class)->findBy(
            ['maxItemPrice' => null],
            ['name' => 'ASC'],
        );

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaSingleProduct::class, 'maxItemPrice');

        // The maxItemPrice field formula appears once: in the SELECT statement
        // The WHERE statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.maxItemPrice IS NOT NULL ORDER BY p.maxItemPrice ASC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaSingleProduct::class, 'maxItemPrice');

        // The maxItemPrice field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p ' .
            'WHERE EXISTS (SELECT 1 FROM ' . FormulaSingleProduct::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > :min) ' .
            'ORDER BY p.orderCount ASC'
        )
            ->setParameter('min', 1)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears twice: once in the SELECT statement and once in the EXISTS subquery
        // The ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaOrderCount);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p ' .
            'WHERE NOT EXISTS (SELECT 1 FROM ' . FormulaSingleProduct::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > :min) ' .
            'ORDER BY p.orderCount ASC'
        )
            ->setParameter('min', 1)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');

        // The orderCount field formula appears twice: once in the SELECT statement and once in the NOT EXISTS subquery
        // The ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaOrderCount);

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

        /** @var FormulaSingleProduct[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . FormulaSingleProduct::class . ' p ' .
            'WHERE p.totalRevenue = (SELECT MAX(p2.totalRevenue) FROM ' . FormulaSingleProduct::class . ' p2) ' .
            'ORDER BY p.name ASC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaSingleProduct::class, 'totalRevenue');

        // The totalRevenue field formula appears twice: once in the SELECT statement and once in the subquery MAX()
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // Product 4 and Product 5 both have the maximum totalRevenue=60
        self::assertCount(2, $products);

        self::assertSame('Product 4', $products[0]->name);
        self::assertSame(60.0, $products[0]->totalRevenue);

        self::assertSame('Product 5', $products[1]->name);
        self::assertSame(60.0, $products[1]->totalRevenue);
    }
}
