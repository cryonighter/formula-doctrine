<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class AggregationFunctionTest extends OrmTestCase
{
    public function testDqlAggregation(): void
    {
        $this->createManyProduct();

        $result = $this->em->createQueryBuilder()
            ->select('SUM(p.orderCount) as countOrders, MAX(p.maxItemPrice) as maxPrice, AVG(p.totalRevenue) as avgRevenue, MIN(p.maxItemPrice) as minMaxPrice')
            ->from(Product::class, 'p')
            ->getQuery()
            ->getSingleResult();

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // Returned the required values
        self::assertSame(10, $result['countOrders']);
        self::assertSame(55, $result['maxPrice']);
        self::assertSame(15, $result['minMaxPrice']);
        self::assertSame(50.0, $result['avgRevenue']);
    }

    public function createManyProduct(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);  // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);        // orderCount=2, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [40.00]);               // orderCount=1, totalRevenue=40
        $this->createProductWithOrderItems($this->makeProduct('Product 6'), [45.00, 50.00, 55.00]); // orderCount=3, totalRevenue=150
    }
}
