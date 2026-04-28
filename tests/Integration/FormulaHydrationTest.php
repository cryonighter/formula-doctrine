<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;

final class FormulaHydrationTest extends OrmTestCase
{
    /**
     * Test that formula fields loaded via DQL have the correct default values when there are no orders
     */
    public function testDqlSingleEntityFormulaFieldDefaultsWhenNoOrders(): void
    {
        $product = $this->makeProduct('Empty Product');
        $this->persist($product);

        $this->queryLogger->reset();

        $result = $this->getProduct($product->id);

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
        $product = $this->makeProduct('Popular Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [10.00, 20.00, 30.00]);

        $this->queryLogger->reset();

        $result = $this->getProduct($product->id);

        // Exactly 1 query - all formulas in one SELECT
        self::assertCount(1, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(3, $result->orderCount);
        self::assertEqualsWithDelta(30.00, $result->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(60.00, $result->totalRevenue, 0.001);
    }

    /**
     * Test that a DQL query uses a single query with subqueries to load formulas (no N+1)
     */
    public function testDqlUsesOneQueryWithSubqueries(): void
    {
        // Creating 3 products with different number of orders
        $p1 = $this->makeProduct('Product 1');
        $p2 = $this->makeProduct('Product 2');
        $p3 = $this->makeProduct('Product 3');
        $this->persist($p1, $p2, $p3);

        $this->persistOrderItems($p1->id, [10.00]);
        $this->persistOrderItems($p2->id, [20.00, 30.00]);

        $this->queryLogger->reset();

        // One SELECT should return all 3 products with formulas
        $products = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p ORDER BY p.id ASC')
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
     * Test that find() method uses only one query to fetch entity with formulas
     */
    public function testFindSingleEntityUsesOneQuery(): void
    {
        $product = $this->makeProduct('Find Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [10.00, 20.00]);

        $this->em->clear();
        $this->queryLogger->reset();

        $found = $this->em->find(Product::class, $product->id);

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
        $product = $this->makeProduct('Identity Map Product');
        $this->persist($product);
        $this->persistOrderItems($product->id, [5.00]);

        // First we load via DQL - Walker works
        $viaDql = $this->getProduct($product->id);

        self::assertSame(1, $viaDql->orderCount);

        $this->queryLogger->reset();

        // find() should return object from Identity Map without extra queries
        $viaFind = $this->em->find(Product::class, $product->id);

        self::assertSame($viaDql, $viaFind);

        // 0 requests - Identity Map
        self::assertCount(0, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(1, $viaFind->orderCount);
        self::assertEqualsWithDelta(5.00, $viaFind->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(5.00, $viaFind->totalRevenue, 0.001);
    }

    /**
     * Test that flush does not persist formula fields
     */
    public function testFormulaFieldsAreNotPersistedOnFlush(): void
    {
        $product = $this->makeProduct('Persist Test');
        $this->persist($product);
        $this->persistOrderItems($product->id, [50.00]);

        $loaded = $this->getProduct($product->id);

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

        $reloaded = $this->getProduct($product->id);

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
        $product = $this->makeProduct('No Update Test');
        $this->persist($product);

        $loaded = $this->getProduct($product->id);

        // Changing the formula field
        $loaded->orderCount = 999;

        // Flush should not attempt to save orderCount=999
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->getProduct($product->id);

        // After reloading, the value is recalculated from the database (0, no orders)
        self::assertSame(0, $reloaded->orderCount);
    }

    /**
     * Test that DQL formulas work correctly on repeated query execution
     */
    public function testDqlFormulasWorkOnRepeatedQueryExecution(): void
    {
        $product = $this->makeProduct('RSM Cache Test');
        $this->persist($product);
        $this->persistOrderItems($product->id, [10.00, 20.00]);

        for ($i = 0; $i < 5; $i++) {
            $loaded = $this->getProduct($product->id);

            self::assertSame(2, $loaded->orderCount);
            self::assertEqualsWithDelta(20.00, $loaded->maxItemPrice, 0.001);
            self::assertEqualsWithDelta(30.00, $loaded->totalRevenue, 0.001);

            $this->em->clear();
        }
    }

    /**
     * Helper method to create a product entity
     */
    private function makeProduct(string $name): Product
    {
        $product = new Product();
        $product->name = $name;

        return $product;
    }

    /**
     * Helper method to persist order items for a product
     */
    private function persistOrderItems(int $productId, array $prices): void
    {
        foreach ($prices as $price) {
            $item = new OrderItem();
            $item->productId = $productId;
            $item->price = (string) $price;
            $item->quantity = 1;
            $this->em->persist($item);
        }

        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();
    }

    /**
     * Helper method for load a product by ID via DQL
     */
    private function getProduct(int $id): Product
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(Product::class, $product);

        return $product;
    }
}
