<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Review;

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

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');

        // The totalRevenue field formula appears four times: once in the SELECT statement
        // and three times in the CASE WHEN statement
        // The ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(4, $mainSql, $formulaTotalRevenue);

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

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');
        $formulaMaxItemPrice = $this->registry->getForProperty(Product::class, 'maxItemPrice');

        // The orderCount field formula appears once: in the CASE WHEN statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // The maxItemPrice field formula appears once: in the CASE WHEN statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);

        // Check all rows
        self::assertCount(3, $result);

        self::assertSame('HighPrice', $result[0]['name']);
        self::assertSame('premium', $result[0]['productTier']);

        self::assertSame('LowPrice', $result[1]['name']);
        self::assertSame('standard', $result[1]['productTier']);

        self::assertSame('NoOrders', $result[2]['name']);
        self::assertSame('inactive', $result[2]['productTier']);
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
            '    WHEN p.totalRevenue > (SELECT AVG(p2.totalRevenue) FROM ' . Product::class . ' p2) THEN \'above_avg\' ' .
            '    ELSE \'below_avg\' ' .
            'END as revenueStatus ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');

        // The totalRevenue field formula appears four times: once in the SELECT statement,
        // twice in the CASE WHEN statement, and once in the AVG subquery
        // The ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(4, $mainSql, $formulaTotalRevenue);

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

    public function testDqlCaseWhenWithCompoundCondition(): void
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
            '    WHEN p.totalRevenue > (SELECT AVG(p2.totalRevenue) FROM ' . Product::class . ' p2) AND p.orderCount > 0 THEN \'above_avg_active\' ' .
            '    WHEN p.orderCount = 0 THEN \'inactive\' ' .
            '    ELSE \'below_avg_active\' ' .
            'END as productStatus ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The totalRevenue field formula appears three times: once in SELECT, once in WHEN condition, once in AVG subquery
        self::assertCountFormulaSubqueries(3, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears twice: once in WHEN compound condition, once in second WHEN
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaOrderCount);

        self::assertCount(4, $result);

        // Product 1: totalRevenue=60 > AVG(30) AND orderCount=3 > 0 → above_avg_active
        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);
        self::assertSame('above_avg_active', $result[0]['productStatus']);

        // Product 4: totalRevenue=40 > AVG(30) AND orderCount=2 > 0 → above_avg_active
        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
        self::assertSame('above_avg_active', $result[1]['productStatus']);

        // Product 2: totalRevenue=20 < AVG(30) → below_avg_active
        self::assertSame('Product 2', $result[2]['name']);
        self::assertSame(20.0, $result[2]['totalRevenue']);
        self::assertSame('below_avg_active', $result[2]['productStatus']);

        // Product 3: orderCount=0 → inactive (totalRevenue condition checked first, but 0 < AVG)
        self::assertSame('Product 3', $result[3]['name']);
        self::assertSame(0.0, $result[3]['totalRevenue']);
        self::assertSame('inactive', $result[3]['productStatus']);
    }

    public function testDqlCaseWhenWithExistsSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue, ' .
            'CASE ' .
            '    WHEN EXISTS (SELECT 1 FROM ' . Product::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > 0) THEN \'active\' ' .
            '    ELSE \'inactive\' ' .
            'END as activityStatus ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The totalRevenue field formula appears once:  in SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once: inside EXISTS subquery
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        self::assertCount(4, $result);

        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);
        self::assertSame('active', $result[0]['activityStatus']);

        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
        self::assertSame('active', $result[1]['activityStatus']);

        self::assertSame('Product 2', $result[2]['name']);
        self::assertSame(20.0, $result[2]['totalRevenue']);
        self::assertSame('active', $result[2]['activityStatus']);

        self::assertSame('Product 3', $result[3]['name']);
        self::assertSame(0.0, $result[3]['totalRevenue']);
        self::assertSame('inactive', $result[3]['activityStatus']); // orderCount=0 → no rows in EXISTS
    }

    public function testDqlCaseWhenWithNotExistsSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue, ' .
            'CASE ' .
            '    WHEN NOT EXISTS (SELECT 1 FROM ' . Product::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > 0) THEN \'no_orders\' ' .
            '    WHEN p.totalRevenue > 50 THEN \'high_revenue\' ' .
            '    ELSE \'normal\' ' .
            'END as productStatus ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The totalRevenue field formula appears twice: once in SELECT, once in WHEN p.totalRevenue > 50
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once inside NOT EXISTS subquery
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        self::assertCount(4, $result);

        // Product 1: orderCount=3 → NOT EXISTS is false, totalRevenue=60 > 50 → high_revenue
        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);
        self::assertSame('high_revenue', $result[0]['productStatus']);

        // Product 4: orderCount=2 → NOT EXISTS is false, totalRevenue=40 ≤ 50 → normal
        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
        self::assertSame('normal', $result[1]['productStatus']);

        // Product 2: orderCount=1 → NOT EXISTS is false, totalRevenue=20 ≤ 50 → normal
        self::assertSame('Product 2', $result[2]['name']);
        self::assertSame(20.0, $result[2]['totalRevenue']);
        self::assertSame('normal', $result[2]['productStatus']);

        // Product 3: orderCount=0 → NOT EXISTS is true → no_orders
        self::assertSame('Product 3', $result[3]['name']);
        self::assertSame(0.0, $result[3]['totalRevenue']);
        self::assertSame('no_orders', $result[3]['productStatus']);
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

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The orderCount field formula appears twice: once in the CASE WHEN statement, once in the ORDER By statement
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaOrderCount);

        // Products with orders first (sorted by orderCount DESC), then products without orders
        self::assertCount(4, $result);

        self::assertSame('Product 2', $result[0]['name']); // orderCount=2
        self::assertSame('Product 4', $result[1]['name']); // orderCount=2
        self::assertSame('Product 1', $result[2]['name']); // orderCount=1
        self::assertSame('Product 3', $result[3]['name']); // orderCount=0, pushed to end
    }

    public function testDqlCaseWhenWithSubqueryInOrderBy(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        // AVG totalRevenue = (60+20+0+40)/4 = 30

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue ' .
            'FROM ' . Product::class . ' p ' .
            'ORDER BY CASE ' .
            '    WHEN p.totalRevenue > (SELECT AVG(p2.totalRevenue) FROM ' . Product::class . ' p2) THEN 0 ' .
            '    ELSE 1 ' .
            'END ASC, p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');

        // The totalRevenue field formula appears three times: once in SELECT, once in AVG subquery
        // The CASE WHEN statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // Products above AVG(30) first, sorted by totalRevenue DESC, then below AVG
        self::assertCount(4, $result);

        self::assertSame('Product 1', $result[0]['name']); // totalRevenue=60 > 30 → priority 0
        self::assertSame('Product 4', $result[1]['name']); // totalRevenue=40 > 30 → priority 0
        self::assertSame('Product 2', $result[2]['name']); // totalRevenue=20 ≤ 30 → priority 1
        self::assertSame('Product 3', $result[3]['name']); // totalRevenue=0  ≤ 30 → priority 1
    }

    public function testDqlCaseWhenInWhere(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue ' .
            'FROM ' . Product::class . ' p ' .
            'WHERE CASE WHEN p.orderCount > 0 THEN p.totalRevenue ELSE 0 END > :minRevenue ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->setParameter('minRevenue', 30)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The totalRevenue field formula appears once: in SELECT statement
        // The CASE WHEN statement AND ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once: in CASE WHEN condition
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 3: orderCount=0 → CASE returns 0 → filtered out
        // Product 2: orderCount=1 → CASE returns 20, 20 ≤ 30 → filtered out
        // Product 1: orderCount=3 → CASE returns 60, 60 > 30 → ok
        // Product 4: orderCount=2 → CASE returns 40, 40 > 30 → ok
        self::assertCount(2, $result);

        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);

        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
    }

    public function testDqlCaseWhenInHaving(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [20.00]);               // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                        // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . Product::class . ' p ' .
            'GROUP BY p.orderCount ' .
            'HAVING CASE WHEN p.orderCount > 0 THEN SUM(p.totalRevenue) ELSE 0 END > :minRevenue ' .
            'ORDER BY p.orderCount ASC'
        )
            ->setParameter('minRevenue', 30)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The totalRevenue field formula appears once: inside SUM() in CASE WHEN
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once: in SELECT statement
        // The CASE WHEN statement AND ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // orderCount=0: CASE returns 0, 0 ≤ 30 → filtered out
        // orderCount=1: CASE returns SUM=20, 20 ≤ 30 → filtered out
        // orderCount=2: CASE returns SUM=40, 40 > 30 → ok
        // orderCount=3: CASE returns SUM=120, 120 > 30 → ok
        self::assertCount(2, $result);

        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(1, $result[0]['cnt']);

        self::assertSame(3, $result[1]['orderCount']);
        self::assertSame(2, $result[1]['cnt']);
    }

    public function testDqlCaseWhenInJoin(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        $this->createReview($productId1, 'Review 1');
        $this->createReview($productId2, 'Review 2');
        $this->createReview($productId3, 'Review 3');
        $this->createReview($productId4, 'Review 4');

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue ' .
            'FROM ' . Product::class . ' p ' .
            'JOIN ' . Review::class . ' r WITH r.product = p AND CASE WHEN p.orderCount > 0 THEN p.totalRevenue ELSE 0 END > :minRevenue ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->setParameter('minRevenue', 30)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The totalRevenue field formula appears once: in SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once: in CASE WHEN condition
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 3: orderCount=0 → CASE returns 0 → filtered out
        // Product 2: orderCount=1 → CASE returns 20, 20 ≤ 30 → filtered out
        // Product 1: orderCount=3 → CASE returns 60, 60 > 30 → ok
        // Product 4: orderCount=2 → CASE returns 40, 40 > 30 → ok
        self::assertCount(2, $result);

        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);

        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
    }

    public function testDqlCaseWhenWithSubqueryInJoin(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        $this->createReview($productId1, 'Review 1');
        $this->createReview($productId2, 'Review 2');
        $this->createReview($productId3, 'Review 3');
        $this->createReview($productId4, 'Review 4');

        // AVG totalRevenue = (60+20+0+40)/4 = 30

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue ' .
            'FROM ' . Product::class . ' p ' .
            'JOIN ' . Review::class . ' r WITH r.product = p ' .
            '    AND CASE WHEN p.orderCount > 0 THEN p.totalRevenue ELSE 0 END > (SELECT AVG(p2.totalRevenue) FROM ' . Product::class . ' p2) ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(Product::class, 'orderCount');

        // The totalRevenue field formula appears three times: once in SELECT, once in AVG subquery
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once: in CASE WHEN condition
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 3: orderCount=0 → CASE returns 0, 0 ≤ AVG(30) → filtered out
        // Product 2: orderCount=1 → CASE returns 20, 20 ≤ AVG(30) → filtered out
        // Product 1: orderCount=3 → CASE returns 60, 60 > AVG(30) → ok
        // Product 4: orderCount=2 → CASE returns 40, 40 > AVG(30) → ok
        self::assertCount(2, $result);

        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);

        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
    }

    private function createReview(int $productId, string $description): void
    {
        $product = $this->em->find(Product::class, $productId);

        $review = new Review();
        $review->product = $product;
        $review->rating = rand(1, 5);
        $review->description = $description;

        $this->em->persist($review);
        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();
    }
}
