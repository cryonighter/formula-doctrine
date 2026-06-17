<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class CaseWhenFormulaTest extends OrmTestCase
{
    public function testDqlCaseWhenWithFormulaField(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);  // totalRevenue=30,  orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // totalRevenue=20,  orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // totalRevenue=0,   orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);        // totalRevenue=60,  orderCount=2
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [45.00, 50.00, 55.00]); // totalRevenue=150, orderCount=3

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
        $this->createProductWithOrderItems($this->makeProduct('Product A'), [5.00]);         // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product B'), [10.00, 20.00]); // orderCount=2
        $this->createProductWithOrderItems($this->makeProduct('Product C'));                 // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product D'), [30.00, 40.00]); // orderCount=2

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

        self::assertSame('Product B', $result[0]['name']); // orderCount=2
        self::assertSame('Product D', $result[1]['name']); // orderCount=2
        self::assertSame('Product A', $result[2]['name']); // orderCount=1
        self::assertSame('Product C', $result[3]['name']); // orderCount=0, pushed to end
    }
}
