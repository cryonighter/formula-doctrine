<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single\FormulaSingleProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single\SingleProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single\Review;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\Proxy;

final class InheritedSingleFormulaTest extends OrmTestCase
{
    /**
     * Test that formula fields loaded via DQL have the correct default values when there are no orders
     */
    public function testDqlSingleEntityFormulaFieldDefaultsWhenNoOrders(): void
    {
        $productId = $this->createSingleProductWithOrderItems(
            $this->makeFormulaSingleProduct('Empty Product'),
        );

        $result = $this->getFormulaSingleProduct($productId);

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(0, $result->orderCount);
        self::assertSame(0.0, $result->totalRevenue);
        self::assertNull($result->maxItemPrice);
    }

    /**
     * Test that formula fields loaded via DQL have the correct values when there are orders
     */
    public function testDqlSingleEntityFormulaFieldValuesIsCorrect(): void
    {
        $productId = $this->createSingleProductWithOrderItems(
            $this->makeFormulaSingleProduct('Popular Product'),
            [10.00, 20.00, 30.00],
        );

        $result = $this->getFormulaSingleProduct($productId);

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(3, $result->orderCount);
        self::assertEqualsWithDelta(30.00, $result->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(60.00, $result->totalRevenue, 0.001);
    }

    /**
     * Test that a DQL query uses a single query with subqueries to load formulas (no N+1)
     * Additionally, it test the correct operation of the limit and offset
     */
    public function testDqlUsesOneQueryWithSubqueriesAndLimit(): void
    {
        // Creating 3 products with different number of orders
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 1'), [10.00]);
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 2'), [20.00, 30.00]);
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 3'));
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 4'));
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 5'), [40.00]);

        // One SELECT should return all 3 products with formulas
        $products = $this->em
            ->createQuery('SELECT p FROM ' . FormulaSingleProduct::class . ' p ORDER BY p.id ASC')
            ->setFirstResult(0)
            ->setMaxResults(3)
            ->getResult();

        // Returned the required amount of products
        self::assertCount(3, $products);

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // SQL contains formula subqueries
        $sql = $this->queryLogger->getQueries()[0];
        self::assertStringContainsString('SELECT COUNT', $sql);
        self::assertStringContainsString('SELECT COALESCE', $sql);

        // The field values are correct
        self::assertSame(1, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
        self::assertSame(0, $products[2]->orderCount);
    }

    /**
     * Test that a findAll() uses a single query with subqueries to load formulas (no N+1)
     */
    public function testFindAllUsesOneQueryWithSubqueries(): void
    {
        // Creating 3 products with different number of orders
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 1'), [20.00]);
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 2'), [30.00, 40.00]);
        $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Product 3'));

        // One SELECT should return all 3 products with formulas
        $products = $this->em->getRepository(FormulaSingleProduct::class)->findAll();

        // Returned the required amount of products
        self::assertCount(3, $products);

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(1, $products[0]->orderCount);
        self::assertSame(2, $products[1]->orderCount);
        self::assertSame(0, $products[2]->orderCount);
    }

    /**
     * Test that find() method uses only one query to fetch entity with formulas
     */
    public function testFindSingleEntityUsesOneQuery(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Find Product'), [10.00, 20.00]);

        $found = $this->em->find(FormulaSingleProduct::class, $productId);

        // Exactly 1 query via find()
        self::assertCount(1, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(2, $found->orderCount);
        self::assertEqualsWithDelta(20.00, $found->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(30.00, $found->totalRevenue, 0.001);
    }

    /**
     * Test that find() after DQL uses Identity Map without extra query
     */
    public function testFindAfterDqlUsesIdentityMapNoExtraQuery(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Identity Map Product'), [5.00]);

        // First we load via DQL - Walker works
        $viaDql = $this->getFormulaSingleProduct($productId);

        self::assertSame(1, $viaDql->orderCount);

        $this->queryLogger->reset();

        // find() should return object from Identity Map without extra queries
        $viaFind = $this->em->find(FormulaSingleProduct::class, $productId);

        self::assertSame($viaDql, $viaFind);

        // 0 requests - Identity Map
        self::assertCount(0, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(1, $viaFind->orderCount);
        self::assertEqualsWithDelta(5.00, $viaFind->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(5.00, $viaFind->totalRevenue, 0.001);
    }

    /**
     * Test that a single entity can be found lazily via a relation
     *
     * With JOINED inheritance, Doctrine cannot create a proxy and loads eagerly
     *
     * @see https://github.com/doctrine/orm/issues/3509
     * Doctrine CANNOT create proxy instances of this entity and will ALWAYS load the entity eagerlyhttps://github.com/doctrine/orm/issues/3509
     * // "Doctrine CANNOT create proxy instances of this entity and will ALWAYS load the entity eagerly"
     */
    public function testRelationFindLazyLoadSingleEntity(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Reviewed Product'), [30.00, 40.00]);
        $reviewId = $this->createReview($productId);

        $found = $this->em->find(Review::class, $reviewId);

        // Exactly 1 query via find()
        self::assertCount(2, $this->queryLogger->getQueries());

        // Verify that product is NOT a Doctrine proxy (already loaded eagerly)
        self::assertNotInstanceOf(Proxy::class, $found->product);

        // The field values are correct (already loaded eagerly)
        self::assertSame(2, $found->product->orderCount);
        self::assertEqualsWithDelta(40.00, $found->product->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(70.00, $found->product->totalRevenue, 0.001);

        // 2 request — Product has already been loaded, there are no additional requests
        self::assertCount(2, $this->queryLogger->getQueries());
    }

    /**
     * Test eager loading of a single entity with a formula field using DQL
     */
    public function testRelationDqlEagerLoadSingleEntity(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Reviewed Product'), [35.00, 45.00]);
        $reviewId = $this->createReview($productId);

        $found = $this->em->createQuery('SELECT r, p FROM ' . Review::class . ' r JOIN r.product p WHERE r.id = :id')
            ->setParameter('id', $reviewId)
            ->getSingleResult();

        // Exactly 1 eagerly query via DQL
        self::assertCount(1, $this->queryLogger->getQueries());

        // Verify that product is NOT a Doctrine proxy (already loaded eagerly)
        self::assertNotInstanceOf(Proxy::class, $found->product);

        // The field values are correct
        self::assertSame(2, $found->product->orderCount);
        self::assertEqualsWithDelta(45.00, $found->product->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(80.00, $found->product->totalRevenue, 0.001);

        // 1 request — Product has already been loaded, there are no additional requests
        self::assertCount(1, $this->queryLogger->getQueries());
    }

    /**
     * Test that flush does not persist formula fields
     */
    public function testFormulaFieldsAreNotPersistedOnFlush(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Persist Test'), [50.00]);

        $loaded = $this->getFormulaSingleProduct($productId);

        // The field values loaded are correct
        self::assertSame('Persist Test', $loaded->name);
        self::assertSame(1, $loaded->orderCount);
        self::assertEqualsWithDelta(50.00, $loaded->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(50.00, $loaded->totalRevenue, 0.001);

        // Modify a regular field and flush
        $loaded->name = 'Updated Name';
        $this->em->flush();

        // Check that flush did not break due to formula fields
        $this->em->clear();

        $reloaded = $this->getFormulaSingleProduct($productId);

        // The field values reloaded are correct
        self::assertSame('Updated Name', $reloaded->name);
        self::assertSame(1, $reloaded->orderCount);
        self::assertEqualsWithDelta(50.00, $loaded->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(50.00, $loaded->totalRevenue, 0.001);
    }

    /**
     * Test that changing a formula field does not trigger an update
     */
    public function testFormulaFieldChangeDoesNotTriggerUpdate(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('No Update Test'));

        $loaded = $this->getFormulaSingleProduct($productId);

        // Changing the formula field
        $loaded->orderCount = 999;

        // Flush should not attempt to save orderCount=999
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->getFormulaSingleProduct($productId);

        // After reloading, the value is recalculated from the database (0, no orders)
        self::assertSame(0, $reloaded->orderCount);
    }

    /**
     * Test that refreshing an entity with formula fields updates the formula fields
     *
     * @throws ORMException
     */
    public function testFormulaFieldUpdatedWhenRefresh(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('No Update Test'), [15.00, 25.00]);

        $loaded = $this->getFormulaSingleProduct($productId);

        $this->em->persist($this->makeSingleOrderItem($loaded, 35.00));
        $this->em->flush();
        $this->em->refresh($loaded);

        // After refreshing, the value is recalculated from the database
        self::assertSame(3, $loaded->orderCount);
        self::assertEqualsWithDelta(35.00, $loaded->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(75.00, $loaded->totalRevenue, 0.001);
    }

    /**
     * Test that DQL formulas work correctly on repeated query execution
     */
    public function testDqlFormulasWorkOnRepeatedQueryExecution(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('RSM Cache Test'), [10.00, 20.00]);

        for ($i = 0; $i < 5; $i++) {
            $loaded = $this->getFormulaSingleProduct($productId);

            self::assertSame(2, $loaded->orderCount);
            self::assertEqualsWithDelta(20.00, $loaded->maxItemPrice, 0.001);
            self::assertEqualsWithDelta(30.00, $loaded->totalRevenue, 0.001);

            $this->em->clear();
        }
    }

    /**
     * Helper method to create a review and return its ID
     */
    private function createReview(int $productId): int
    {
        // To simplify debugging SqlWalker, it is better to use the find() function
        $product = $this->em->find(SingleProduct::class, $productId);

        $review = new Review();
        $review->product = $product;
        $review->rating = rand(1, 5);
        $review->description = 'Test review';

        $this->em->persist($review);
        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $review->id;
    }

    /**
     * Test eager loading of a single entity with a relation to a formula entity.
     * For merged entities, loading occurs in 2 requests, but immediately, without accessing the related entity.
     */
    public function testFindRelationEntityEagerLoadSingleEntity(): void
    {
        $productId = $this->createSingleProductWithOrderItems($this->makeFormulaSingleProduct('Rating Product'), [40.00, 45.00]);
        $this->createManySingleReviews($productId, [3, 4, 5]);

        $found = $this->em->getRepository(Rating::class)->findOneBy(['product' => $productId]);

        // Exactly 2 eagerly query via findOneBy
        self::assertCount(2, $this->queryLogger->getQueries());

        // Verify that product is NOT a Doctrine proxy (already loaded eagerly)
        self::assertNotInstanceOf(Proxy::class, $found->product);

        // The field values are correct
        self::assertSame(2, $found->product->orderCount);
        self::assertEqualsWithDelta(45.00, $found->product->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(85.00, $found->product->totalRevenue, 0.001);
        self::assertEqualsWithDelta(4.00, $found->stars, 0.001);

        // 2 request — Product has already been loaded, there are no additional requests
        self::assertCount(2, $this->queryLogger->getQueries());
    }

    /**
     * Helper method to create a product entity
     */
    protected function makeFormulaSingleProduct(string $name): FormulaSingleProduct
    {
        $product = new FormulaSingleProduct();
        $product->name = $name;

        return $product;
    }

    /**
     * Helper method to create an order item entity
     */
    protected function makeSingleOrderItem(SingleProduct $product, float $price): OrderItem
    {
        $item = new OrderItem();
        $item->product = $product;
        $item->price = (string) $price;
        $item->quantity = 1;

        return $item;
    }

    /**
     * Helper method to create multiple reviews for a product
     */
    private function createManySingleReviews(int $productId, array $ratings): void
    {
        // To simplify debugging SqlWalker, it is better to use the find() function
        $product = $this->em->find(SingleProduct::class, $productId);

        foreach ($ratings as $rating) {
            $review = new Review();
            $review->product = $product;
            $review->rating = $rating;
            $review->description = 'Test review';

            $this->em->persist($review);
        }

        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();
    }

    /**
     * Helper method to persist product and order items for him
     */
    protected function createSingleProductWithOrderItems(SingleProduct $product, array $prices = []): int
    {
        $this->em->persist($product);
        $this->em->persist(new Rating($product));

        foreach ($prices as $price) {
            $this->em->persist($this->makeSingleOrderItem($product, $price));
        }

        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $product->id;
    }

    /**
     * Helper method for load a product by ID via DQL
     */
    protected function getFormulaSingleProduct(int $id): FormulaSingleProduct
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . FormulaSingleProduct::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(FormulaSingleProduct::class, $product);

        return $product;
    }
}
