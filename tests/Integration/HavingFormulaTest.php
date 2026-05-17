<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class HavingFormulaTest extends OrmTestCase
{
    public function testDqlHavingWithFormulaField(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [40.00, 45.00, 50.00]);

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . Product::class . ' p ' .
            'GROUP BY p.orderCount ' .
            'HAVING p.orderCount >= :minCount'
        )
            ->setParameter('minCount', 2)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Check filtered grouped results
        self::assertCount(2, $result);

        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(1, $result[0]['cnt']);

        self::assertSame(3, $result[1]['orderCount']);
        self::assertSame(2, $result[1]['cnt']);
    }

    public function testDqlHavingWithAggregateAndFormula(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 30.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [35.00, 40.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 5'));

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . Product::class . ' p ' .
            'GROUP BY p.orderCount ' .
            'HAVING COUNT(p.id) > :minProducts AND p.orderCount > :minOrders ' .
            'ORDER BY p.orderCount'
        )
            ->setParameter('minProducts', 1)
            ->setParameter('minOrders', 1)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Check filtered grouped results
        self::assertCount(1, $result);

        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(3, $result[0]['cnt']);
    }

    public function testDqlHavingWithComplexCondition(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Alpha'), [5.00]);
        $this->createProductWithOrderItems($this->makeProduct('Beta'), [10.00, 15.00]);
        $this->createProductWithOrderItems($this->makeProduct('Gamma'), [20.00, 25.00]);
        $this->createProductWithOrderItems($this->makeProduct('Delta'), [30.00, 35.00, 40.00]);
        $this->createProductWithOrderItems($this->makeProduct('Epsilon'));

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . Product::class . ' p ' .
            'GROUP BY p.name, p.orderCount ' .
            'HAVING p.orderCount BETWEEN :minCount AND :maxCount ' .
            'ORDER BY p.orderCount DESC'
        )
            ->setParameter('minCount', 1)
            ->setParameter('maxCount', 2)
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Check filtered grouped results
        self::assertCount(3, $result);

        self::assertSame('Beta', $result[0]['name']);
        self::assertSame(2, $result[0]['orderCount']);

        self::assertSame('Gamma', $result[1]['name']);
        self::assertSame(2, $result[1]['orderCount']);

        self::assertSame('Alpha', $result[2]['name']);
        self::assertSame(1, $result[2]['orderCount']);
    }
}
