<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\FormulaJoinedProduct;

final class HavingFormulaTest extends JoinedInheritedOrmTestCase
{
    public function testDqlHavingWithFormulaField(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);  // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);        // orderCount=2, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [40.00, 45.00, 50.00]); // orderCount=3, totalRevenue=135

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . FormulaJoinedProduct::class . ' p ' .
            'GROUP BY p.orderCount ' .
            'HAVING p.orderCount >= :minCount'
        )
            ->setParameter('minCount', 2)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears only: in the SELECT statement
        // The GROUP BY and HAVING statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Check filtered grouped results
        self::assertCount(2, $result);

        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(1, $result[0]['cnt']);

        self::assertSame(3, $result[1]['orderCount']);
        self::assertSame(2, $result[1]['cnt']);
    }

    public function testDqlHavingWithAggregateAndFormula(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);  // orderCount=2, totalRevenue=15
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);        // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 30.00]); // orderCount=2, totalRevenue=55
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [35.00, 40.00]); // orderCount=2, totalRevenue=75
        $this->createProductWithOrderItems($this->makeProduct('Product 5'));                 // orderCount=0, totalRevenue=0

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . FormulaJoinedProduct::class . ' p ' .
            'GROUP BY p.orderCount ' .
            'HAVING COUNT(p.id) > :minProducts AND p.orderCount > :minOrders ' .
            'ORDER BY p.orderCount'
        )
            ->setParameter('minProducts', 1)
            ->setParameter('minOrders', 1)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears only: in the SELECT statement
        // The GROUP BY, HAVING and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Check filtered grouped results
        self::assertCount(1, $result);

        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(3, $result[0]['cnt']);
    }

    public function testDqlHavingWithComplexCondition(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00]);                // orderCount=1, totalRevenue=5
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [10.00, 15.00]);        // orderCount=2, totalRevenue=25
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [20.00, 25.00]);        // orderCount=2, totalRevenue=45
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [30.00, 35.00, 40.00]); // orderCount=3, totalRevenue=105
        $this->createProductWithOrderItems($this->makeProduct('Product 5'));                        // orderCount=0, totalRevenue=0

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . FormulaJoinedProduct::class . ' p ' .
            'GROUP BY p.name, p.orderCount ' .
            'HAVING p.orderCount BETWEEN :minCount AND :maxCount ' .
            'ORDER BY p.orderCount DESC'
        )
            ->setParameter('minCount', 1)
            ->setParameter('maxCount', 2)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears only: in the SELECT statement
        // The GROUP BY, HAVING and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Check filtered grouped results
        self::assertCount(3, $result);

        self::assertSame('Product 2', $result[0]['name']);
        self::assertSame(2, $result[0]['orderCount']);

        self::assertSame('Product 3', $result[1]['name']);
        self::assertSame(2, $result[1]['orderCount']);

        self::assertSame('Product 1', $result[2]['name']);
        self::assertSame(1, $result[2]['orderCount']);
    }

    public function testDqlHavingWithExistsSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);  // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [10.00, 15.00, 20.00]); // orderCount=3, totalRevenue=45
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 30.00]);        // orderCount=2, totalRevenue=55
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [35.00]);               // orderCount=1, totalRevenue=35
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [15.00, 20.00]);        // orderCount=2, totalRevenue=35
        $this->createProductWithOrderItems($this->makeProduct('Product 6'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 7'), [15.00]);               // orderCount=1, totalRevenue=15

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . FormulaJoinedProduct::class . ' p ' .
            'GROUP BY p.orderCount ' .
            'HAVING EXISTS (' .
            '    SELECT 1 FROM ' . FormulaJoinedProduct::class . ' p2 ' .
            '    WHERE p2.orderCount = p.orderCount AND p2.totalRevenue > :minRevenue' .
            ') ' .
            'ORDER BY p.orderCount ASC'
        )
            ->setParameter('minRevenue', 30)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');
        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The orderCount field formula appears twice: once for p and once for p2
        // The GROUP BY and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaOrderCount);

        // The totalRevenue field formula appears only: in the subquery
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // Groups where EXISTS a product with same orderCount AND totalRevenue > 30
        self::assertCount(3, $result);

        self::assertSame(1, $result[0]['orderCount']);
        self::assertSame(2, $result[0]['cnt']);

        self::assertSame(2, $result[1]['orderCount']);
        self::assertSame(2, $result[1]['cnt']);

        self::assertSame(3, $result[2]['orderCount']);
        self::assertSame(2, $result[2]['cnt']);
    }

    public function testDqlHavingWithScalarSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [20.00, 25.00]);       // orderCount=2, totalRevenue=45
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [5.00]);               // orderCount=1, totalRevenue=5
        $this->createProductWithOrderItems($this->makeProduct('Product 5'));                       // orderCount=0, totalRevenue=0

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, SUM(p.totalRevenue) as groupRevenue ' .
            'FROM ' . FormulaJoinedProduct::class . ' p ' .
            'GROUP BY p.orderCount ' .
            'HAVING SUM(p.totalRevenue) > (' .
            '    SELECT AVG(p2.totalRevenue) FROM ' . FormulaJoinedProduct::class . ' p2' .
            ') ' .
            'ORDER BY p.orderCount ASC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');
        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The orderCount field formula appears only: in the SELECT statement
        // The GROUP BY and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // The totalRevenue field formula appears four times: once in the SELECT statement,
        // once in the HAVING clause, and once in the subquery
        self::assertCountFormulaSubqueries(3, $mainSql, $formulaTotalRevenue);

        // AVG(totalRevenue) across all 5 products = (30+30+45+5+0)/5 = 22
        // Groups: orderCount=0 → SUM=0, orderCount=1 → SUM=5, orderCount=2 → SUM=45, orderCount=3 → SUM=60
        // Only groups with SUM > 22: orderCount=2 (45) and orderCount=3 (60)
        self::assertCount(2, $result);

        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(45, $result[0]['groupRevenue']);

        self::assertSame(3, $result[1]['orderCount']);
        self::assertSame(60, $result[1]['groupRevenue']);
    }
}
