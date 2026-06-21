<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Rating;

final class ImplicitJoinFormulaTest extends OrmTestCase
{
    public function testDqlImplicitJoin(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2, totalRevenue=60

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.orderCount, p.totalRevenue ' .
            'FROM ' . Product::class . ' p, ' . Rating::class . ' r ' .
            'WHERE r.product = p AND p.orderCount > :minOrders ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->setParameter('minOrders', 1)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 3 (orderCount=0) filtered out, Product 2 (orderCount=1) filtered out
        self::assertCount(2, $result);

        self::assertSame('Product 4', $result[0]['name']);
        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(60.0, $result[0]['totalRevenue']);

        self::assertSame('Product 1', $result[1]['name']);
        self::assertSame(3, $result[1]['orderCount']);
        self::assertSame(30.0, $result[1]['totalRevenue']);
    }

    public function testDqlImplicitJoinWithFormulaFilter(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // totalRevenue=60

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue, p.maxItemPrice ' .
            'FROM ' . Product::class . ' p, ' . Rating::class . ' r ' .
            'WHERE r.product = p AND p.totalRevenue >= :minRevenue AND p.maxItemPrice IS NOT NULL ' .
            'ORDER BY p.maxItemPrice DESC'
        )
            ->setParameter('minRevenue', 25)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');

        // Verify that the formula was only executed once
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // Product 2 (totalRevenue=20 < 25) and Product 3 (totalRevenue=0, maxItemPrice=null) filtered out
        self::assertCount(2, $result);

        self::assertSame('Product 4', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);
        self::assertSame(35.0, $result[0]['maxItemPrice']);

        self::assertSame('Product 1', $result[1]['name']);
        self::assertSame(30.0, $result[1]['totalRevenue']);
        self::assertSame(15.0, $result[1]['maxItemPrice']);
    }
}
