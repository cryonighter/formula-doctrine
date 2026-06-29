<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent;

use Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity\Category;

final class FormulaInFormulaTest extends IndependentOrmTestCase
{
    /**
     * Test that nested formula fields (formula referencing another formula) are hydrated correctly
     */
    public function testDqlNestedFormulaFieldsAreHydratedCorrectly(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00]);        // orderCount=2, totalRevenue=30
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'), [15.00]);               // orderCount=1, totalRevenue=15

        $this->createCategoryWithProducts($this->makeCategory('Category 1'), [$productId1, $productId2]); // categoryRevenue=90, amountOrders=5
        $this->createCategoryWithProducts($this->makeCategory('Category 2'), [$productId3]);              // categoryRevenue=15, amountOrders=1
        $this->createCategoryWithProducts($this->makeCategory('Category 3'));                             // categoryRevenue=0,  amountOrders=0

        /** @var Category[] $categories */
        $categories = $this->em
            ->createQuery('SELECT c FROM ' . Category::class . ' c ORDER BY c.id ASC')
            ->getResult();

        // Exactly 1 query — all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // Category 1
        self::assertEqualsWithDelta(90.0, $categories[0]->categoryRevenue, 0.001);
        self::assertSame(5, $categories[0]->amountOrders);
        self::assertTrue($categories[0]->active);

        // Category 2
        self::assertEqualsWithDelta(15.0, $categories[1]->categoryRevenue, 0.001);
        self::assertSame(1, $categories[1]->amountOrders);
        self::assertTrue($categories[1]->active);

        // Category 3 — no products, COALESCE returns defaults
        self::assertEqualsWithDelta(0.0, $categories[2]->categoryRevenue, 0.001);
        self::assertSame(0, $categories[2]->amountOrders);
        self::assertFalse($categories[2]->active);
    }

    /**
     * Test that nested formula fields (formula referencing another formula) are hydrated correctly
     */
    public function testFindNestedFormulaFieldsAreHydratedCorrectly(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00]);        // orderCount=2, totalRevenue=30
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'), [15.00]);               // orderCount=1, totalRevenue=15

        $this->createCategoryWithProducts($this->makeCategory('Category 1'), [$productId1, $productId2]); // categoryRevenue=90, amountOrders=5
        $this->createCategoryWithProducts($this->makeCategory('Category 2'), [$productId3]);              // categoryRevenue=15, amountOrders=1
        $this->createCategoryWithProducts($this->makeCategory('Category 3'));                             // categoryRevenue=0,  amountOrders=0

        $categories = $this->em->getRepository(Category::class)->findBy([], ['id' => 'ASC']);

        // Exactly 1 query — all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // Category 1
        self::assertEqualsWithDelta(90.0, $categories[0]->categoryRevenue, 0.001);
        self::assertSame(5, $categories[0]->amountOrders);
        self::assertTrue($categories[0]->active);

        // Category 2
        self::assertEqualsWithDelta(15.0, $categories[1]->categoryRevenue, 0.001);
        self::assertSame(1, $categories[1]->amountOrders);
        self::assertTrue($categories[1]->active);

        // Category 3 — no products, COALESCE returns defaults
        self::assertEqualsWithDelta(0.0, $categories[2]->categoryRevenue, 0.001);
        self::assertSame(0, $categories[2]->amountOrders);
        self::assertFalse($categories[2]->active);
    }

    /**
     * Test nested formula fields with overlapping products across categories,
     * filtered and sorted by formula fields
     */
    public function testDqlNestedFormulaWithOverlappingProductsFilteredAndSorted(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00]);                      // orderCount=1, totalRevenue=10
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]);               // orderCount=2, totalRevenue=50
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'), [30.00, 30.00, 30.00]);        // orderCount=3, totalRevenue=90
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [20.00]);                      // orderCount=1, totalRevenue=20
        $productId5 = $this->createProductWithOrderItems($this->makeProduct('Product 5'), [10.00, 20.00, 40.00, 50.00]); // orderCount=4, totalRevenue=120
        $productId6 = $this->createProductWithOrderItems($this->makeProduct('Product 6'));                               // orderCount=0, totalRevenue=0

        $this->createCategoryWithProducts($this->makeCategory('Category 1'), [$productId1, $productId2]);              // amountOrders=3, categoryRevenue=60
        $this->createCategoryWithProducts($this->makeCategory('Category 2'), [$productId2, $productId3]);              // amountOrders=5, categoryRevenue=140
        $this->createCategoryWithProducts($this->makeCategory('Category 3'), [$productId3, $productId4, $productId5]); // amountOrders=8, categoryRevenue=230
        $this->createCategoryWithProducts($this->makeCategory('Category 4'), [$productId1, $productId6]);              // amountOrders=1, categoryRevenue=10
        $this->createCategoryWithProducts($this->makeCategory('Category 5'), [$productId4, $productId5, $productId6]); // amountOrders=5, categoryRevenue=140
        $this->createCategoryWithProducts($this->makeCategory('Category 6'));                                          // amountOrders=0, categoryRevenue=0

        /** @var Category[] $categories */
        $categories = $this->em
            ->createQuery(
                'SELECT c FROM ' . Category::class . ' c ' .
                'WHERE c.amountOrders > :minOrders ' .
                'ORDER BY c.categoryRevenue DESC'
            )
            ->setParameter('minOrders', 1)
            ->getResult();

        // Exactly 1 query — all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // C4 (amountOrders=1) and C6 (amountOrders=0) filtered out
        self::assertCount(4, $categories);

        // Sorted by categoryRevenue DESC: C3=230, C2=140, C5=140, C1=60
        self::assertSame('Category 3', $categories[0]->name);
        self::assertEqualsWithDelta(230.0, $categories[0]->categoryRevenue, 0.001);
        self::assertSame(8, $categories[0]->amountOrders);

        // C2 and C5 both have categoryRevenue=140 — order between them is not guaranteed
        $middleNames = [$categories[1]->name, $categories[2]->name];
        self::assertContains('Category 2', $middleNames);
        self::assertContains('Category 5', $middleNames);
        self::assertEqualsWithDelta(140.0, $categories[1]->categoryRevenue, 0.001);
        self::assertEqualsWithDelta(140.0, $categories[2]->categoryRevenue, 0.001);

        self::assertSame('Category 1', $categories[3]->name);
        self::assertEqualsWithDelta(60.0, $categories[3]->categoryRevenue, 0.001);
        self::assertSame(3, $categories[3]->amountOrders);
    }

    /**
     * Test nested formula fields with overlapping products across categories,
     * filtered and sorted by formula fields
     */
    public function testFindNestedFormulaWithOverlappingProductsFilteredAndSorted(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00]);               // orderCount=1, totalRevenue=10
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]);        // orderCount=2, totalRevenue=50
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'), [30.00, 30.00, 30.00]); // orderCount=3, totalRevenue=90
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [20.00, 40.00]);        // orderCount=2, totalRevenue=60
        $productId5 = $this->createProductWithOrderItems($this->makeProduct('Product 5'), [50.00]);               // orderCount=1, totalRevenue=50
        $productId6 = $this->createProductWithOrderItems($this->makeProduct('Product 6'), [30.00, 70.00]);        // orderCount=2, totalRevenue=100

        $this->createCategoryWithProducts($this->makeCategory('Category 1'), [$productId1, $productId2]);              // amountOrders=3, categoryRevenue=60
        $this->createCategoryWithProducts($this->makeCategory('Category 2'), [$productId2, $productId3]);              // amountOrders=5, categoryRevenue=140
        $this->createCategoryWithProducts($this->makeCategory('Category 3'), [$productId3, $productId4, $productId5]); // amountOrders=6, categoryRevenue=200
        $this->createCategoryWithProducts($this->makeCategory('Category 4'), [$productId1, $productId6]);              // amountOrders=3, categoryRevenue=110
        $this->createCategoryWithProducts($this->makeCategory('Category 5'), [$productId4, $productId5, $productId6]); // amountOrders=5, categoryRevenue=210
        $this->createCategoryWithProducts($this->makeCategory('Category 6'));                                          // amountOrders=0, categoryRevenue=0

        $categories = $this->em->getRepository(Category::class)->findBy(
            ['amountOrders' => 5],
            ['categoryRevenue' => 'DESC'],
        );

        // Exactly 1 query — FormulaMiddleware substitutes formulas
        self::assertCount(1, $this->queryLogger->getQueries());

        // Only C2 and C5 match amountOrders=5
        self::assertCount(2, $categories);

        // Sorted by categoryRevenue DESC: C5=210 first, C2=140 second
        self::assertSame('Category 5', $categories[0]->name);
        self::assertSame(5, $categories[0]->amountOrders);
        self::assertEqualsWithDelta(210.0, $categories[0]->categoryRevenue, 0.001);

        self::assertSame('Category 2', $categories[1]->name);
        self::assertSame(5, $categories[1]->amountOrders);
        self::assertEqualsWithDelta(140.0, $categories[1]->categoryRevenue, 0.001);
    }

    /**
     * Test nested formula fields with a self-referencing subquery on Category.
     * Both the outer query and the subquery use formula fields of Category,
     * which themselves reference formula fields of Product.
     */
    public function testNestedFormulaWithSelfReferencingSubquery(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00]);               // orderCount=1, totalRevenue=10
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]);        // orderCount=2, totalRevenue=50
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'), [30.00, 30.00, 30.00]); // orderCount=3, totalRevenue=90
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [20.00, 40.00]);        // orderCount=2, totalRevenue=60
        $productId5 = $this->createProductWithOrderItems($this->makeProduct('Product 5'), [50.00]);               // orderCount=1, totalRevenue=50
        $productId6 = $this->createProductWithOrderItems($this->makeProduct('Product 6'), [30.00, 70.00]);        // orderCount=2, totalRevenue=100

        $this->createCategoryWithProducts($this->makeCategory('Category 1'), [$productId1, $productId2]);              // amountOrders=3, categoryRevenue=60
        $this->createCategoryWithProducts($this->makeCategory('Category 2'), [$productId2, $productId3]);              // amountOrders=5, categoryRevenue=140
        $this->createCategoryWithProducts($this->makeCategory('Category 3'), [$productId3, $productId4, $productId5]); // amountOrders=6, categoryRevenue=200
        $this->createCategoryWithProducts($this->makeCategory('Category 4'), [$productId1, $productId6]);              // amountOrders=3, categoryRevenue=110
        $this->createCategoryWithProducts($this->makeCategory('Category 5'), [$productId4, $productId5, $productId6]); // amountOrders=5, categoryRevenue=210
        $this->createCategoryWithProducts($this->makeCategory('Category 6'));                                          // amountOrders=0, categoryRevenue=0

        // AVG = (60+140+200+110+210+0)/6 = 120

        /** @var Category[] $categories */
        $categories = $this->em->createQueryBuilder()
            ->select('c')
            ->from(Category::class, 'c')
            ->where('c.categoryRevenue > (SELECT AVG(c2.categoryRevenue) FROM ' . Category::class . ' c2)')
            ->orderBy('c.categoryRevenue', 'DESC')
            ->getQuery()
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        // C1=60, C4=110, C6=0 filtered out (≤ AVG 120)
        self::assertCount(3, $categories);

        // Sorted DESC: C5=210, C3=200, C2=140
        self::assertSame('Category 5', $categories[0]->name);
        self::assertEqualsWithDelta(210.0, $categories[0]->categoryRevenue, 0.001);
        self::assertSame(5, $categories[0]->amountOrders);

        self::assertSame('Category 3', $categories[1]->name);
        self::assertEqualsWithDelta(200.0, $categories[1]->categoryRevenue, 0.001);
        self::assertSame(6, $categories[1]->amountOrders);

        self::assertSame('Category 2', $categories[2]->name);
        self::assertEqualsWithDelta(140.0, $categories[2]->categoryRevenue, 0.001);
        self::assertSame(5, $categories[2]->amountOrders);
    }
}
