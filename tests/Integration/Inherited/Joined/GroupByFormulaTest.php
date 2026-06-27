<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\FormulaJoinedProduct;

final class GroupByFormulaTest extends JoinedInheritedOrmTestCase
{
    public function testDqlGroupByFormulaField(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);  // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);        // orderCount=2
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [40.00]);               // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 6'), [45.00, 50.00, 55.00]); // orderCount=3

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as productCount FROM ' . FormulaJoinedProduct::class . ' p GROUP BY p.orderCount'
        )->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the CASE WHEN statement
        // The GROUP BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Check grouped results
        self::assertCount(4, $result);

        // Verify the grouping results
        self::assertSame(0, $result[0]['orderCount']);
        self::assertSame(1, $result[0]['productCount']);

        self::assertSame(1, $result[1]['orderCount']);
        self::assertSame(2, $result[1]['productCount']);

        self::assertSame(2, $result[2]['orderCount']);
        self::assertSame(1, $result[2]['productCount']);

        self::assertSame(3, $result[3]['orderCount']);
        self::assertSame(2, $result[3]['productCount']);
    }

    public function testDqlGroupByMultipleFields(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);  // orderCount=2, totalRevenue=15
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);        // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [30.00]);        // orderCount=2, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [25.00, 35.00]); // orderCount=2, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [40.00]);        // orderCount=1, totalRevenue=40
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [50.00]);        // orderCount=1, totalRevenue=50

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . FormulaJoinedProduct::class . ' p GROUP BY p.name, p.orderCount ' .
            'ORDER BY p.name'
        )->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the CASE WHEN statement
        // The GROUP BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Check grouped results
        self::assertCount(4, $result);

        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(1, $result[0]['orderCount']);
        self::assertSame(2, $result[0]['cnt']);

        self::assertSame('Product 1', $result[1]['name']);
        self::assertSame(2, $result[1]['orderCount']);
        self::assertSame(1, $result[1]['cnt']);

        self::assertSame('Product 2', $result[3]['name']);
        self::assertSame(1, $result[2]['orderCount']);
        self::assertSame(2, $result[2]['cnt']);

        self::assertSame('Product 2', $result[3]['name']);
        self::assertSame(2, $result[3]['orderCount']);
        self::assertSame(1, $result[3]['cnt']);
    }
}