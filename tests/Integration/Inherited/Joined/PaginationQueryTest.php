<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\FormulaJoinedProduct;

final class PaginationQueryTest extends JoinedInheritedOrmTestCase
{
    /**
     * Test that DQL pagination works correctly
     */
    public function testDqlPagination(): void
    {
        $this->createManyProduct();

        $limit = 3;
        $offset = 2;

        $resultOne = $this->em->createQueryBuilder()
            ->select('p')
            ->from(FormulaJoinedProduct::class, 'p')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');
        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaJoinedProduct::class, 'maxItemPrice');
        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The orderCount field formula appears once: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);
        // The maxItemPrice field formula appears twice: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);
        // The totalRevenue field formula appears once: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // Returned the required amount of products
        self::assertCount($limit, $resultOne);

        // The field values are correct
        self::assertSame('Product 3', $resultOne[0]->name);
        self::assertSame('Product 4', $resultOne[1]->name);
        self::assertSame('Product 5', $resultOne[2]->name);

        $this->queryLogger->reset();

        $resultTwo = $this->em->createQueryBuilder()
            ->select('p')
            ->from(FormulaJoinedProduct::class, 'p')
            ->setMaxResults($limit)
            ->setFirstResult($offset + 2)
            ->getQuery()
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        // Returned the required amount of products
        self::assertCount(2, $resultTwo);

        // The field values are correct
        self::assertSame('Product 5', $resultTwo[0]->name);
        self::assertSame('Product 6', $resultTwo[1]->name);
    }

    /**
     * Test that findBy() pagination works correctly
     */
    public function testFindPagination(): void
    {
        $this->createManyProduct();

        $limit = 3;
        $offset = 2;

        $resultOne = $this->em->getRepository(FormulaJoinedProduct::class)
            ->findBy([], [], $limit, $offset);

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaJoinedProduct::class, 'orderCount');
        $formulaMaxItemPrice = $this->registry->getForProperty(FormulaJoinedProduct::class, 'maxItemPrice');
        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The orderCount field formula appears once: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);
        // The maxItemPrice field formula appears twice: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaMaxItemPrice);
        // The totalRevenue field formula appears once: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // Returned the required amount of products
        self::assertCount($limit, $resultOne);

        // The field values are correct
        self::assertSame('Product 3', $resultOne[0]->name);
        self::assertSame('Product 4', $resultOne[1]->name);
        self::assertSame('Product 5', $resultOne[2]->name);

        $this->queryLogger->reset();

        $resultTwo = $this->em->getRepository(FormulaJoinedProduct::class)
            ->findBy([], [], $limit, $offset + 2);

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        // Returned the required amount of products
        self::assertCount(2, $resultTwo);

        // The field values are correct
        self::assertSame('Product 5', $resultTwo[0]->name);
        self::assertSame('Product 6', $resultTwo[1]->name);
    }

    public function createManyProduct(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'));
        $this->createProductWithOrderItems($this->makeProduct('Product 2'));
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));
        $this->createProductWithOrderItems($this->makeProduct('Product 4'));
        $this->createProductWithOrderItems($this->makeProduct('Product 5'));
        $this->createProductWithOrderItems($this->makeProduct('Product 6'));
    }
}
