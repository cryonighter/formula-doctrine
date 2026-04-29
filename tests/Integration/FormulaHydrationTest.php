<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\DBAL\FormulaMiddleware;
use Cryonighter\FormulaDoctrine\DependencyInjection\FormulaDoctrineConfigurator;
use Cryonighter\FormulaDoctrine\EventListener\LoadClassMetadataListener;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Review;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\ORM\ORMSetup;
use Doctrine\Persistence\Proxy;

final class FormulaHydrationTest extends OrmTestCase
{
    protected function createEntityManager(QueryLogger $queryLogger): EntityManagerInterface
    {
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/Fixture/Entity'],
            isDevMode: true,
        );

        // Connecting FormulaDoctrineConfigurator directly, without Symfony
        $registry = new FormulaRegistry(new FormulaMetadataFactory());
        $configurator = new FormulaDoctrineConfigurator($registry);
        $configurator->configure($ormConfig);

        $dbalConfig = new DbalConfiguration();
        $dbalConfig->setMiddlewares([
            new FormulaMiddleware($registry),
            $queryLogger,
        ]);

        $em = new EntityManager(
            $this->createConnection($dbalConfig),
            $ormConfig,
        );

        $eventManager = $em->getEventManager();

        $eventManager->addEventListener(
            Events::loadClassMetadata,
            new LoadClassMetadataListener($registry),
        );

        return $em;
    }

    /**
     * Test that formula fields loaded via DQL have the correct default values when there are no orders
     */
    public function testDqlSingleEntityFormulaFieldDefaultsWhenNoOrders(): void
    {
        $productId = $this->createProductWithOrderItems(
            $this->makeProduct('Empty Product'),
        );

        $result = $this->getProduct($productId);

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
        $productId = $this->createProductWithOrderItems(
            $this->makeProduct('Popular Product'),
            [10.00, 20.00, 30.00],
        );

        $result = $this->getProduct($productId);

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
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));

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
     * Test that a findAll() uses a single query with subqueries to load formulas (no N+1)
     */
    public function testFindAllUsesOneQueryWithSubqueries(): void
    {
        // Creating 3 products with different number of orders
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [20.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [30.00, 40.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));

        // One SELECT should return all 3 products with formulas
        $products = $this->em->getRepository(Product::class)->findAll();

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
        $productId = $this->createProductWithOrderItems($this->makeProduct('Find Product'), [10.00, 20.00]);

        $found = $this->em->find(Product::class, $productId);

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
        $productId = $this->createProductWithOrderItems($this->makeProduct('Identity Map Product'), [5.00]);

        // First we load via DQL - Walker works
        $viaDql = $this->getProduct($productId);

        self::assertSame(1, $viaDql->orderCount);

        $this->queryLogger->reset();

        // find() should return object from Identity Map without extra queries
        $viaFind = $this->em->find(Product::class, $productId);

        self::assertSame($viaDql, $viaFind);

        // 0 requests - Identity Map
        self::assertCount(0, $this->queryLogger->getQueries());

        // The field values are correct
        self::assertSame(1, $viaFind->orderCount);
        self::assertEqualsWithDelta(5.00, $viaFind->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(5.00, $viaFind->totalRevenue, 0.001);
    }

    public function testFindRelationLazyLoadSingleEntity(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product'), [30.00, 40.00]);
        $reviewId = $this->createReview($productId);

        $found = $this->em->find(Review::class, $reviewId);

        // Exactly 1 query via find()
        self::assertCount(1, $this->queryLogger->getQueries());

        // Verify that product is a Doctrine proxy (not yet loaded)
        self::assertInstanceOf(Proxy::class, $found->product);

        // The field values are correct (product loaded via lazy load)
        self::assertSame(2, $found->product->orderCount);
        self::assertEqualsWithDelta(40.00, $found->product->maxItemPrice, 0.001);
        self::assertEqualsWithDelta(70.00, $found->product->totalRevenue, 0.001);

        // 2 requests - find Review and lazy load Product
        self::assertCount(2, $this->queryLogger->getQueries());
    }

    public function testDqlRelationEagerLoadSingleEntity(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product'), [35.00, 45.00]);
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
        $productId = $this->createProductWithOrderItems($this->makeProduct('Persist Test'), [50.00]);

        $loaded = $this->getProduct($productId);

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

        $reloaded = $this->getProduct($productId);

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
        $productId = $this->createProductWithOrderItems($this->makeProduct('No Update Test'));

        $loaded = $this->getProduct($productId);

        // Changing the formula field
        $loaded->orderCount = 999;

        // Flush should not attempt to save orderCount=999
        $this->em->flush();
        $this->em->clear();

        $reloaded = $this->getProduct($productId);

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
        $productId = $this->createProductWithOrderItems($this->makeProduct('No Update Test'), [15.00, 25.00]);

        $loaded = $this->getProduct($productId);

        $this->em->persist($this->makeOrderItem($loaded, 35.00));
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
        $productId = $this->createProductWithOrderItems($this->makeProduct('RSM Cache Test'), [10.00, 20.00]);

        for ($i = 0; $i < 5; $i++) {
            $loaded = $this->getProduct($productId);

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
        $product = $this->em->find(Product::class, $productId);

        $review = new Review();
        $review->product = $product;
        $review->description = 'Test review';

        $this->em->persist($review);
        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $review->id;
    }

    /**
     * Helper method to create multiple reviews for a product
     */
    private function createManyReviews(int $productId, array $ratings): void
    {
        // To simplify debugging SqlWalker, it is better to use the find() function
        $product = $this->em->find(Product::class, $productId);

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
}
