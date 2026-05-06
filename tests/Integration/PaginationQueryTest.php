<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class PaginationQueryTest extends OrmTestCase
{
    /**
     * Test that DQL pagination works correctly
     */
    public function testDqlPagination(): void
    {
        $this->createManyProduct();

        $limit = 3;

        $result = $this->em->createQueryBuilder()
            ->select('p')
            ->from(Product::class, 'p')
            ->setMaxResults($limit)
            ->setFirstResult(2)
            ->getQuery()
            ->getResult();

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // Returned the required amount of products
        self::assertCount($limit, $result);

        // The field values are correct
        self::assertSame('Product 3', $result[0]->name);
        self::assertSame('Product 4', $result[1]->name);
        self::assertSame('Product 5', $result[2]->name);
    }

    public function createManyProduct(): void
    {
        $this->createProductWithOrderItems(
            $this->makeProduct('Product 1'),
        );
        $this->createProductWithOrderItems(
            $this->makeProduct('Product 2'),
        );
        $this->createProductWithOrderItems(
            $this->makeProduct('Product 3'),
        );
        $this->createProductWithOrderItems(
            $this->makeProduct('Product 4'),
        );
        $this->createProductWithOrderItems(
            $this->makeProduct('Product 5'),
        );
        $this->createProductWithOrderItems(
            $this->makeProduct('Product 6'),
        );
    }
}
