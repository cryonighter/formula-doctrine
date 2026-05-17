<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class GroupByFormulaTest extends OrmTestCase
{
    public function testDqlGroupByFormulaField(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 5'), [40.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 6'), [45.00, 50.00, 55.00]);

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.orderCount, COUNT(p.id) as productCount FROM ' . Product::class . ' p GROUP BY p.orderCount'
        )->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

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
        $product1 = $this->makeProduct('Product A');
        $product2 = $this->makeProduct('Product B');
        $product3 = $this->makeProduct('Product A');

        $this->createProductWithOrderItems($product1, [5.00, 10.00]);
        $this->createProductWithOrderItems($product2, [20.00, 25.00]);
        $this->createProductWithOrderItems($product3, [30.00, 35.00]);

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.orderCount, COUNT(p.id) as cnt ' .
            'FROM ' . Product::class . ' p GROUP BY p.name, p.orderCount ORDER BY p.name, p.orderCount'
        )->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Check grouped results
        self::assertCount(2, $result);

        self::assertSame('Product A', $result[0]['name']);
        self::assertSame(2, $result[0]['orderCount']);

        self::assertSame('Product B', $result[1]['name']);
        self::assertSame(2, $result[1]['orderCount']);
    }
}