<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\FormulaSingleProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity\Rating;

final class ImplicitJoinFormulaTest extends SingleInheritedOrmTestCase
{
    public function testDqlImplicitJoin(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // orderCount=3, totalRevenue=30
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // orderCount=1, totalRevenue=20
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // orderCount=0, totalRevenue=0
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // orderCount=2, totalRevenue=60

        $this->createManyReviews($productId1, [4, 5]); // stars=4.5
        $this->createManyReviews($productId2, [2, 4]); // stars=3.0
        $this->createManyReviews($productId3, [1]);    // stars=1.0
        $this->createManyReviews($productId4, [3, 5]); // stars=4.0

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.orderCount, p.totalRevenue, r.stars ' .
            'FROM ' . FormulaSingleProduct::class . ' p, ' . Rating::class . ' r ' .
            'WHERE r.product = p AND p.orderCount > :minOrders ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->setParameter('minOrders', 1)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaOrderCount = $this->registry->getForProperty(FormulaSingleProduct::class, 'orderCount');
        $formulaStars = $this->registry->getForProperty(Rating::class, 'stars');

        // The orderCount field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaOrderCount);

        // The stars field formula appears once: in the SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaStars);

        // Product 3 (orderCount=0) filtered out, Product 2 (orderCount=1) filtered out
        self::assertCount(2, $result);

        self::assertSame('Product 4', $result[0]['name']);
        self::assertSame(2, $result[0]['orderCount']);
        self::assertSame(60.0, $result[0]['totalRevenue']);
        self::assertSame(4.0, $result[0]['stars']);

        self::assertSame('Product 1', $result[1]['name']);
        self::assertSame(3, $result[1]['orderCount']);
        self::assertSame(30.0, $result[1]['totalRevenue']);
        self::assertSame(4.5, $result[1]['stars']);
    }

    public function testDqlImplicitJoinWithFormulaFilter(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [5.00, 10.00, 15.00]); // totalRevenue=30
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);              // totalRevenue=20
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'));                       // totalRevenue=0
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [25.00, 35.00]);       // totalRevenue=60

        $this->createManyReviews($productId1, [3, 5]); // stars=4.0
        $this->createManyReviews($productId2, [2, 4]); // stars=3.0
        $this->createManyReviews($productId3, [1]);    // stars=1.0
        $this->createManyReviews($productId4, [4, 5]); // stars=4.5

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue, p.maxItemPrice, r.stars ' .
            'FROM ' . FormulaSingleProduct::class . ' p, ' . Rating::class . ' r ' .
            'WHERE r.product = p AND p.totalRevenue >= :minRevenue AND p.maxItemPrice IS NOT NULL AND r.stars >= :minStars ' .
            'ORDER BY r.stars DESC, p.maxItemPrice DESC'
        )
            ->setParameter('minRevenue', 25)
            ->setParameter('minStars', 4)
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaSingleProduct::class, 'totalRevenue');
        $formulaStars = $this->registry->getForProperty(Rating::class, 'stars');

        // The totalRevenue field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaTotalRevenue);

        // The stars field formula appears once: in the SELECT statement
        // The WHERE and ORDER BY statement must use the alias from the first SELECT statement
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaStars);

        // Product 2 (totalRevenue=20 < 25) → filtered out
        // Product 3 (totalRevenue=0, maxItemPrice=null) → filtered out
        // Product 1 (totalRevenue=30 >= 25, stars=4.0 >= 4) → ok
        // Product 4 (totalRevenue=60 >= 25, stars=4.5 >= 4) → ok
        self::assertCount(2, $result);

        // Sorted by stars DESC first, then maxItemPrice DESC
        self::assertSame('Product 4', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);
        self::assertSame(35.0, $result[0]['maxItemPrice']);
        self::assertSame(4.5, $result[0]['stars']);

        self::assertSame('Product 1', $result[1]['name']);
        self::assertSame(30.0, $result[1]['totalRevenue']);
        self::assertSame(15.0, $result[1]['maxItemPrice']);
        self::assertSame(4.0, $result[1]['stars']);
    }
}
