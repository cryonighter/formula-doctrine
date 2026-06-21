<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Review;

final class NestedSubqueryFormulaTest extends OrmTestCase
{
    public function testDqlFourLevelNestedSubquery(): void
    {
        // Product 1: 3 order items, totalRevenue=90, has a high-rated review → qualifies at all levels
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [20.00, 30.00, 40.00]);
        $this->createReview($productId1, 5);

        // Product 2: 2 order items, totalRevenue=50, has a low-rated review → filtered out at level 4
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]);
        $this->createReview($productId2, 2);

        // Product 3: 1 order item with low price, totalRevenue=5 → filtered out at level 3 (price too low)
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'), [5.00]);
        $this->createReview($productId3, 5);

        // Product 4: no order items, totalRevenue=0 → filtered out at level 2 (below avg revenue)
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'));
        $this->createReview($productId4, 5);

        // Product 5: 2 order items, totalRevenue=70, has a high-rated review → qualifies at all levels
        $productId5 = $this->createProductWithOrderItems($this->makeProduct('Product 5'), [30.00, 40.00]);
        $this->createReview($productId5, 4);

        /** @var Rating[] $ratings */
        $ratings = $this->em->createQuery(
            // Level 1: select Ratings joined with their Products, filter by formula field stars
            'SELECT rt, p FROM ' . Rating::class . ' rt ' .
            'JOIN rt.product p ' .
            'WHERE rt.stars >= :minStars ' .

            // Level 2: product must be in the set of products with above-average totalRevenue
            //          grouped by orderCount, filtered by HAVING with EXISTS
            'AND p.id IN (' .
            '    SELECT p2.id FROM ' . Product::class . ' p2 ' .
            '    GROUP BY p2.id ' .
            '    HAVING p2.totalRevenue > :minRevenue ' .

            // Level 3: EXISTS — the product group must have at least one OrderItem
            //          priced above the minimum price of "good" products
            '    AND EXISTS (' .
            '        SELECT 1 FROM ' . OrderItem::class . ' oi ' .
            '        WHERE oi.product = p2 ' .
            '        AND oi.price > :minPrice ' .

            // Level 4: IN — "good" products are those that have at least one high-rated Review
            '        AND oi.product IN (' .
            '            SELECT IDENTITY(rv.product) FROM ' . Review::class . ' rv ' .
            '            WHERE rv.rating >= :minRating' .
            '        )' .
            '    )' .
            ') ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->setParameter('minStars', 3)    // Level 1: Rating.stars formula >= 3
            ->setParameter('minRevenue', 20) // Level 2: totalRevenue formula > 20
            ->setParameter('minPrice', 15)   // Level 3: at least one item price > 15
            ->setParameter('minRating', 4)   // Level 4: review rating >= 4
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(Product::class, 'totalRevenue');
        $formulaStars = $this->registry->getForProperty(Rating::class, 'stars');

        // The totalRevenue field formula appears twice: once for p (ORDER BY) and once for p2 (HAVING) aliases
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // The stars field formula appears once: for rt alias in WHERE
        self::assertCountFormulaSubqueries(1, $mainSql, $formulaStars);

        // Only Product 1 (revenue=90) and Product 5 (revenue=70) pass all 4 levels
        // Product 2: low-rated review (rating=2) → filtered at level 4
        // Product 3: order item price=5 ≤ 15 → filtered at level 3
        // Product 4: no order items, revenue=0 ≤ 20 → filtered at level 2
        self::assertCount(2, $ratings);

        self::assertSame('Product 1', $ratings[0]->product->name);
        self::assertSame(90.0, $ratings[0]->product->totalRevenue);

        self::assertSame('Product 5', $ratings[1]->product->name);
        self::assertSame(70.0, $ratings[1]->product->totalRevenue);
    }

    private function createReview(int $productId, int $rating): void
    {
        $product = $this->em->find(Product::class, $productId);

        $review = new Review();
        $review->product = $product;
        $review->rating = $rating;
        $review->description = 'Review for ' . $product->name;

        $this->em->persist($review);
        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();
    }
}
