<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent;

use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Product;

final class OrderByFormulaTest extends IndependentOrmTestCase
{
    public function testDqlOrderByAsc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2, totalRevenue=60

        /** @var Product[] $products */
        $products = $this->em->createQuery('SELECT p FROM ' . Product::class . ' p ORDER BY p.orderCount ASC')
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned all products
        self::assertCount(4, $products);

        // The products are ordered correctly by orderCount ASC
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame(0, $products[0]->orderCount);

        self::assertSame('Product 2', $products[1]->name);
        self::assertSame(1, $products[1]->orderCount);

        self::assertSame('Product 4', $products[2]->name);
        self::assertSame(2, $products[2]->orderCount);

        self::assertSame('Product 1', $products[3]->name);
        self::assertSame(3, $products[3]->orderCount);
    }

    public function testFindByOrderAsc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);         // orderCount=2, totalRevenue=15
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 30.00, 35.00]); // orderCount=3, totalRevenue=90
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                        // orderCount=0, totalRevenue=0

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            [],
            ['orderCount' => 'ASC']
        );

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned all products
        self::assertCount(4, $products);

        // The products are ordered correctly by orderCount ASC
        self::assertSame('Product 4', $products[0]->name);
        self::assertSame(0, $products[0]->orderCount);

        self::assertSame('Product 2', $products[1]->name);
        self::assertSame(1, $products[1]->orderCount);

        self::assertSame('Product 1', $products[2]->name);
        self::assertSame(2, $products[2]->orderCount);

        self::assertSame('Product 3', $products[3]->name);
        self::assertSame(3, $products[3]->orderCount);
    }

    public function testDqlOrderByDesc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00]);                // orderCount=1, totalRevenue=5
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 25.00, 30.00]); // orderCount=3, totalRevenue=75
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [35.00, 45.00]);        // orderCount=2, totalRevenue=80

        /** @var Product[] $products */
        $products = $this->em->createQuery('SELECT p FROM ' . Product::class . ' p ORDER BY p.orderCount DESC')
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        $mainSql = $this->queryLogger->getQueries()[0];

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned all products
        self::assertCount(3, $products);

        // The products are ordered correctly by orderCount DESC
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(3, $products[0]->orderCount);

        self::assertSame('Product 3', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);

        self::assertSame('Product 1', $products[2]->name);
        self::assertSame(1, $products[2]->orderCount);
    }

    public function testFindByOrderDesc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00]);                // orderCount=1, totalRevenue=5
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 25.00, 30.00]); // orderCount=3, totalRevenue=75
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [35.00, 45.00]);        // orderCount=2, totalRevenue=80

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            [],
            ['orderCount' => 'DESC']
        );

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned all products
        self::assertCount(3, $products);

        // The products are ordered correctly by orderCount DESC
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(3, $products[0]->orderCount);

        self::assertSame('Product 3', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);

        self::assertSame('Product 1', $products[2]->name);
        self::assertSame(1, $products[2]->orderCount);
    }

    public function testDqlOrderByWithWhereCondition(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);         // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);                      // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 35.00, 40.00, 45.00]); // orderCount=4, totalRevenue=145
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [50.00, 55.00]);               // orderCount=2, totalRevenue=105

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.orderCount >= :minCount ORDER BY p.totalRevenue DESC'
        )
            ->setParameter('minCount', 2)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned the filtered products
        self::assertCount(3, $products);

        // The products are ordered correctly by orderCount DESC
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame(4, $products[0]->orderCount);
        self::assertEqualsWithDelta(145.00, $products[0]->totalRevenue, 0.001);

        self::assertSame('Product 4', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);
        self::assertEqualsWithDelta(105.00, $products[1]->totalRevenue, 0.001);

        self::assertSame('Product 1', $products[2]->name);
        self::assertSame(3, $products[2]->orderCount);
        self::assertEqualsWithDelta(30.00, $products[2]->totalRevenue, 0.001);
    }

    public function testFindByWithWhereAndOrder(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00]);                       // orderCount=1, totalRevenue=5
        $this->createProductWithOrderItems($this->makeProduct('Product 2'));                               // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [20.00, 30.00]);               // orderCount=2, totalRevenue=50
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 15.00, 20.00]);        // orderCount=3, totalRevenue=45
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [25.00, 35.00]);               // orderCount=2, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 6'), [35.00, 40.00, 45.00, 50.00]); // orderCount=4, totalRevenue=170
        $this->createProductWithOrderItems($this->makeProduct('Product 7'));                               // orderCount=0, totalRevenue=0

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            ['orderCount' => 2],
            ['totalRevenue' => 'DESC']
        );

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Returned the filtered products
        self::assertCount(2, $products);

        // The field values are correct
        self::assertSame('Product 5', $products[0]->name);
        self::assertSame(2, $products[0]->orderCount);
        self::assertEqualsWithDelta(60.00, $products[0]->totalRevenue, 0.001);

        self::assertSame('Product 3', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);
        self::assertEqualsWithDelta(50.00, $products[1]->totalRevenue, 0.001);
    }
}
