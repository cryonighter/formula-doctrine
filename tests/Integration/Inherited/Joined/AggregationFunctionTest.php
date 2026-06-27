<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\FormulaJoinedProduct;

final class AggregationFunctionTest extends JoinedInheritedOrmTestCase
{
    public function testDqlAggregation(): void
    {
        $this->createManyProduct();

        $result = $this->em->createQueryBuilder()
            ->select('SUM(p.orderCount) as countOrders, MAX(p.maxItemPrice) as maxPrice, AVG(p.totalRevenue) as avgRevenue, MIN(p.maxItemPrice) as minMaxPrice')
            ->from(FormulaJoinedProduct::class, 'p')
            ->getQuery()
            ->getSingleResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');
        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaJoinedProduct::class, 'maxItemPrice');
        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The orderCount field formula appears once: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);
        // The maxItemPrice field formula appears twice: in the SELECT statement
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaMaxItemPrice);
        // The totalRevenue field formula appears once: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

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
