<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class CaseWhenFormulaTest extends OrmTestCase
{
    public function testDqlCaseWhenWithFormulaField(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);  // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);        // orderCount=2, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [45.00, 50.00, 55.00]); // orderCount=3, totalRevenue=150

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue, ' .
            'CASE WHEN p.totalRevenue = 0 THEN \'none\' ' .
            'WHEN p.totalRevenue < 50 THEN \'low\' ' .
            'WHEN p.totalRevenue < 100 THEN \'medium\' ' .
            'ELSE \'high\' END as revenueCategory ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY p.totalRevenue ASC'
        )
            ->getResult();

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'totalRevenue')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once per usage in CASE WHEN
        self::assertSame(4, substr_count($mainSql, $subSql));

        // Check all rows
        self::assertCount(5, $result);

        self::assertSame('Product 3', $result[0]['name']);
        self::assertSame('none', $result[0]['revenueCategory']);

        self::assertSame('Product 2', $result[1]['name']);
        self::assertSame('low', $result[1]['revenueCategory']);

        self::assertSame('Product 1', $result[2]['name']);
        self::assertSame('low', $result[2]['revenueCategory']);

        self::assertSame('Product 4', $result[3]['name']);
        self::assertSame('medium', $result[3]['revenueCategory']);

        self::assertSame('Product 5', $result[4]['name']);
        self::assertSame('high', $result[4]['revenueCategory']);
    }

    public function testDqlCaseWhenWithMultipleFormulaFields(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('NoOrders'));                   // orderCount=0, maxItemPrice=null
        $this->createProductWithOrderItems($this->makeProduct('LowPrice'), [5.00, 8.00]);     // orderCount=2, maxItemPrice=8
        $this->createProductWithOrderItems($this->makeProduct('HighPrice'), [90.00, 100.00]); // orderCount=2, maxItemPrice=100

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, ' .
            'CASE WHEN p.orderCount = 0 THEN \'inactive\' ' .
            'WHEN p.maxItemPrice > 50 THEN \'premium\' ' .
            'ELSE \'standard\' END as productTier ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY p.name ASC'
        )
            ->getResult();

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // Check all rows
        self::assertCount(3, $result);

        self::assertSame('HighPrice', $result[0]['name']);
        self::assertSame('premium', $result[0]['productTier']);

        self::assertSame('LowPrice', $result[1]['name']);
        self::assertSame('standard', $result[1]['productTier']);

        self::assertSame('NoOrders', $result[2]['name']);
        self::assertSame('inactive', $result[2]['productTier']);
    }

    public function testDqlCaseWhenInOrderBy(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00]);         // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [10.00, 20.00]); // orderCount=2
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                 // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [30.00, 40.00]); // orderCount=2

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY CASE WHEN p.orderCount = 0 THEN 1 ELSE 0 END ASC, p.orderCount DESC'
        )
            ->getResult();

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // Products with orders first (sorted by orderCount DESC), then products without orders
        self::assertCount(4, $result);

        self::assertSame('Product 2', $result[0]['name']); // orderCount=2
        self::assertSame('Product 4', $result[1]['name']); // orderCount=2
        self::assertSame('Product 1', $result[2]['name']); // orderCount=1
        self::assertSame('Product 3', $result[3]['name']); // orderCount=0, pushed to end
    }

    public function testDqlCaseWhenWithSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        // AVG totalRevenue = (60+20+0+40)/4 = 30

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue, ' .
            'CASE ' .
            '    WHEN p.totalRevenue = 0 THEN \'none\' ' .
            '    WHEN p.totalRevenue > (SELECT AVG(p2.totalRevenue) FROM ' . Product::class . ' p2) AND p.orderCount > 0 THEN \'above_avg\' ' .
            '    ELSE \'below_avg\' ' .
            'END as revenueStatus ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'totalRevenue')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // The formula appears four times: once in the SELECT statement, twice in the CASE WHEN statement,
        // and once in the AVG subquery. The ORDER BY statement must use the alias from the first SELECT statement.
        self::assertSame(4, substr_count($mainSql, $subSql));

        self::assertCount(4, $result);

        // Product 1: totalRevenue=60 > AVG(30) → above_avg
        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);
        self::assertSame('above_avg', $result[0]['revenueStatus']);

        // Product 4: totalRevenue=40 > AVG(30) → above_avg
        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
        self::assertSame('above_avg', $result[1]['revenueStatus']);

        // Product 2: totalRevenue=20 < AVG(30) → below_avg
        self::assertSame('Product 2', $result[2]['name']);
        self::assertSame(20.0, $result[2]['totalRevenue']);
        self::assertSame('below_avg', $result[2]['revenueStatus']);

        // Product 3: totalRevenue=0 → none (checked before AVG comparison)
        self::assertSame('Product 3', $result[3]['name']);
        self::assertSame(0.0, $result[3]['totalRevenue']);
        self::assertSame('none', $result[3]['revenueStatus']);
    }
}
