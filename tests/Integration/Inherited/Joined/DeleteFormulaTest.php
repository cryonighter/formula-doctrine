<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\FormulaJoinedProduct;

final class DeleteFormulaTest extends JoinedInheritedOrmTestCase
{
    public function testDqlDeleteWhereFormulaField(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p WHERE p.orderCount >= :min'
        )
            ->setParameter('min', 2)
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the WHERE statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 1 (orderCount=3) and Product 4 (orderCount=2) deleted
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame('Product 3', $products[1]->name);
    }

    public function testDqlDeleteWhereBetween(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p WHERE p.orderCount BETWEEN :min AND :max'
        )
            ->setParameter('min', 1)
            ->setParameter('max', 2)
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the WHERE statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 2 (orderCount=1) and Product 4 (orderCount=2) deleted
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 1', $products[0]->name);
        self::assertSame('Product 3', $products[1]->name);
    }

    public function testDqlDeleteWhereIn(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p WHERE p.orderCount IN (:counts)'
        )
            ->setParameter('counts', [1, 3])
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the WHERE statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 1 (orderCount=3) and Product 2 (orderCount=1) deleted
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame('Product 4', $products[1]->name);
    }

    public function testDqlDeleteWhereNotIn(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p WHERE p.orderCount NOT IN (:counts)'
        )
            ->setParameter('counts', [1, 3])
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: in the WHERE statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 3 (orderCount=0) and Product 4 (orderCount=2) deleted
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 1', $products[0]->name);
        self::assertSame('Product 2', $products[1]->name);
    }

    public function testDqlDeleteWhereIsNull(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]); // maxItemPrice=10
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);       // maxItemPrice=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                // maxItemPrice=null
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                // maxItemPrice=null

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p WHERE p.maxItemPrice IS NULL'
        )
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaJoinedProduct::class, 'maxItemPrice');

        // The maxItemPrice field formula appears once: in the WHERE statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);

        // Product 3 and Product 4 have no order items → maxItemPrice=null
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 1', $products[0]->name);
        self::assertSame('Product 2', $products[1]->name);
    }

    public function testDqlDeleteWhereIsNotNull(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]); // maxItemPrice=10
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);       // maxItemPrice=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                // maxItemPrice=null
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));                // maxItemPrice=null

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p WHERE p.maxItemPrice IS NOT NULL'
        )
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaJoinedProduct::class, 'maxItemPrice');

        // The maxItemPrice field formula appears once: in the WHERE statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);

        // Product 1 and Product 2 have order items → maxItemPrice is not null
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame('Product 4', $products[1]->name);
    }

    public function testDqlDeleteWhereExists(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p ' .
            'WHERE EXISTS (SELECT 1 FROM ' . FormulaJoinedProduct::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > :min)'
        )
            ->setParameter('min', 1)
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: inside EXISTS subquery
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 1 (orderCount=3) and Product 4 (orderCount=2) have orderCount > 1
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame('Product 3', $products[1]->name);
    }

    public function testDqlDeleteWhereNotExists(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p ' .
            'WHERE NOT EXISTS (SELECT 1 FROM ' . FormulaJoinedProduct::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > :min)'
        )
            ->setParameter('min', 1)
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The orderCount field formula appears once: inside NOT EXISTS subquery
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 2 (orderCount=1) and Product 3 (orderCount=0) do not have orderCount > 1
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 1', $products[0]->name);
        self::assertSame('Product 4', $products[1]->name);
    }

    public function testDqlDeleteWhereInSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);  // totalRevenue=15
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]); // totalRevenue=50
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                 // totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [50.00, 60.00]); // totalRevenue=110

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p ' .
            'WHERE p.id IN (' .
            '    SELECT p2.id FROM ' . FormulaJoinedProduct::class . ' p2 WHERE p2.totalRevenue > :minRevenue' .
            ')'
        )
            ->setParameter('minRevenue', 20)
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The totalRevenue field formula appears once: inside IN subquery
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // Product 2 (totalRevenue=50 > 20) and Product 4 (totalRevenue=110 > 20) deleted
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 1', $products[0]->name);
        self::assertSame('Product 3', $products[1]->name);
    }

    public function testDqlDeleteWhereScalarSubquery(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // totalRevenue=40
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [10.00, 30.00]);        // totalRevenue=40

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p ' .
            'WHERE p.totalRevenue = (SELECT MAX(p2.totalRevenue) FROM ' . FormulaJoinedProduct::class . ' p2)'
        )
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The totalRevenue field formula appears twice: in WHERE left-hand side and inside MAX() subquery
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // Only Product 1 has the maximum totalRevenue=60
        self::assertSame(1, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(4, $products);
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame('Product 3', $products[1]->name);
        self::assertSame('Product 4', $products[2]->name);
        self::assertSame('Product 5', $products[3]->name);
    }

    public function testDqlDeleteWhereCompoundCondition(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2, totalRevenue=60

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p ' .
            'WHERE p.totalRevenue > :minRevenue ' .
            'AND EXISTS (SELECT 1 FROM ' . FormulaJoinedProduct::class . ' p2 WHERE p2.id = p.id AND p2.orderCount > :minOrders)'
        )
            ->setParameter('minRevenue', 25)
            ->setParameter('minOrders', 1)
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The totalRevenue field formula appears once: in WHERE left-hand side
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once: inside EXISTS subquery
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 1: totalRevenue=30 > 25 → orderCount=3 > 1 → deleted
        // Product 2: totalRevenue=20 > 25 → orderCount=1 < 1 → filtered out
        // Product 3: totalRevenue=0  > 25 → orderCount=0 < 1 → filtered out
        // Product 4: totalRevenue=60 > 25 → orderCount=2 > 1 → deleted
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame('Product 3', $products[1]->name);
    }

    public function testDqlDeleteWhereCaseWhen(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1, totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0, totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2, totalRevenue=60

        $affected = $this->em->createQuery(
            'DELETE ' . FormulaJoinedProduct::class . ' p ' .
            'WHERE CASE WHEN p.orderCount > 0 THEN p.totalRevenue ELSE 0 END > :minRevenue'
        )
            ->setParameter('minRevenue', 25)
            ->execute();

        // Exactly 4 query — inherited DELETE is performed by writing to a temporary table, this takes 6 queries
        self::assertCount(6, $this->queryLogger->getQueries());

        // All formulas are contained in the second query (INSERT into a temporary table)
        $mainSql = $this->queryLogger->getQueries()[1];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');
        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');

        // The totalRevenue field formula appears once: in CASE WHEN THEN branch
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // The orderCount field formula appears once: in CASE WHEN condition
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // Product 1: orderCount=3 > 0 → CASE returns totalRevenue=30, 30 > 25 → deleted
        // Product 2: orderCount=1 > 0 → CASE returns totalRevenue=20, 20 ≤ 25 → filtered out
        // Product 3: orderCount=0 → CASE returns 0, 0 ≤ 25 → filtered out
        // Product 4: orderCount=2 > 0 → CASE returns totalRevenue=60, 60 > 25 → deleted
        self::assertSame(2, $affected);

        $this->em->clear();

        $products = $this->em->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // The field values are correct
        self::assertCount(2, $products);
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame('Product 3', $products[1]->name);
    }
}
