<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class OrderByFormulaTest extends OrmTestCase
{
    public function testDqlOrderByAsc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);

        /** @var Product[] $products */
        $products = $this->em->createQuery('SELECT p FROM ' . Product::class . ' p ORDER BY p.orderCount ASC')
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned all products
        self::assertCount(4, $products);

        // The products are ordered correctly by orderCount ASC
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame(0, $products[0]->orderCount);

        self::assertSame('Product 2', $products[1]->name);
        self::assertSame(1, $products[1]->orderCount);

        self::assertSame('Product 4', $products[2]->name);
        self::assertSame(2, $products[2]->orderCount);

        self::assertSame('Product 1', $products[3]->name);
        self::assertSame(3, $products[3]->orderCount);
    }

    public function testFindByOrderAsc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 30.00, 35.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            [],
            ['orderCount' => 'ASC']
        );

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned all products
        self::assertCount(4, $products);

        // The products are ordered correctly by orderCount ASC
        self::assertSame('Product 4', $products[0]->name);
        self::assertSame(0, $products[0]->orderCount);

        self::assertSame('Product 2', $products[1]->name);
        self::assertSame(1, $products[1]->orderCount);

        self::assertSame('Product 1', $products[2]->name);
        self::assertSame(2, $products[2]->orderCount);

        self::assertSame('Product 3', $products[3]->name);
        self::assertSame(3, $products[3]->orderCount);
    }

    public function testDqlOrderByDesc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 25.00, 30.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [35.00, 40.00]);

        /** @var Product[] $products */
        $products = $this->em->createQuery('SELECT p FROM ' . Product::class . ' p ORDER BY p.orderCount DESC')
            ->getResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned all products
        self::assertCount(3, $products);

        // The products are ordered correctly by orderCount DESC
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(3, $products[0]->orderCount);

        self::assertSame('Product 3', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);

        self::assertSame('Product 1', $products[2]->name);
        self::assertSame(1, $products[2]->orderCount);
    }

    public function testFindByOrderDesc(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 25.00, 30.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [35.00, 40.00]);

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            [],
            ['orderCount' => 'DESC']
        );

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned all products
        self::assertCount(3, $products);

        // The products are ordered correctly by orderCount DESC
        self::assertSame('Product 2', $products[0]->name);
        self::assertSame(3, $products[0]->orderCount);

        self::assertSame('Product 3', $products[1]->name);
        self::assertSame(2, $products[1]->orderCount);

        self::assertSame('Product 1', $products[2]->name);
        self::assertSame(1, $products[2]->orderCount);
    }

    public function testDqlOrderByWithWhereCondition(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'), [25.00, 35.00, 40.00, 45.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [50.00, 55.00]);

        /** @var Product[] $products */
        $products = $this->em->createQuery(
            'SELECT p FROM ' . Product::class . ' p WHERE p.orderCount >= :minCount ORDER BY p.orderCount DESC'
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

        // Returned the filtered products
        self::assertCount(3, $products);

        // The products are ordered correctly by orderCount DESC
        self::assertSame('Product 3', $products[0]->name);
        self::assertSame(4, $products[0]->orderCount);

        self::assertSame('Product 1', $products[1]->name);
        self::assertSame(3, $products[1]->orderCount);

        self::assertSame('Product 4', $products[2]->name);
        self::assertSame(2, $products[2]->orderCount);
    }

    public function testFindByWithWhereAndOrder(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Alpha'), [5.00]);
        $this->createProductWithOrderItems($this->makeProduct('Beta'), [10.00, 15.00, 20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Gamma'), [25.00, 30.00]);
        $this->createProductWithOrderItems($this->makeProduct('Delta'), [35.00, 40.00, 45.00, 50.00]);
        $this->createProductWithOrderItems($this->makeProduct('Epsilon'));

        /** @var Product[] $products */
        $products = $this->em->getRepository(Product::class)->findBy(
            ['orderCount' => 2],
            ['name' => 'ASC']
        );

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $formulaSql = $this->registry->getForProperty(Product::class, 'orderCount')->sql;
        $mainSql = $this->queryLogger->getQueries()[0];
        $subSql = strstr($formulaSql, '{this}', true) ?: $formulaSql;

        // Verify that the formula was only executed once
        self::assertSame(1, substr_count($mainSql, $subSql));

        // Returned the filtered products
        self::assertCount(1, $products);

        // The field values are correct
        self::assertSame('Gamma', $products[0]->name);
        self::assertSame(2, $products[0]->orderCount);
    }
}
