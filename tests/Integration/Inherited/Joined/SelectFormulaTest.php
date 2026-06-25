<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\FormulaJoinedProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\JoinedProduct;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity\Review;
use Doctrine\ORM\Exception\ORMException;
use Doctrine\Persistence\Proxy;

final class SelectFormulaTest extends JoinedInheritedOrmTestCase
{
    /**
     * Test that formula fields loaded via DQL have the correct default values when there are no orders
     */
    public function testDqlSingleEntityFormulaFieldDefaultsWhenNoOrders(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Empty Product'));

        $result = $this->getProduct($productId);

        // Exactly 1 query — all formula substitutions in one SQL
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
        $productId = $this->createProductWithOrderItems($this->makeProduct('Popular Product'), [10.00, 20.00, 30.00]);

        $result = $this->getProduct($productId);

        // Exactly 1 query — all formula substitutions in one SQL
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
    public function testDqlUsesOneQueryWithSubqueries(): void
    {
        // Creating 3 products with different number of orders
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00, 30.00]);
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));

        // One SELECT should return all 3 products with formulas
        $products = $this->em
            ->createQuery('SELECT p FROM ' . FormulaJoinedProduct::class . ' p ORDER BY p.id ASC')
            ->getResult();

        // Returned the required amount of products
        self::assertCount(3, $products);

        // Exactly 1 query — all formula substitutions in one SQL
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
        $products = $this->em->getRepository(FormulaJoinedProduct::class)->findAll();

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

        $found = $this->em->find(FormulaJoinedProduct::class, $productId);

        // Exactly 1 query — all formula substitutions in one SQL
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
        $viaFind = $this->em->find(FormulaJoinedProduct::class, $productId);

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
     * Doctrine CANNOT create proxy instances of this entity and will ALWAYS load the entity eagerly
     */
    public function testRelationFindLazyLoadSingleEntity(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product'), [30.00, 40.00]);
        $reviewId = $this->createReview($productId, 'Test review', rand(1, 5));

        $found = $this->em->find(Review::class, $reviewId);

        // Exactly 1 query — all formula substitutions in one SQL
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

    public function testDqlSubqueryInJoinWith(): void
    {
        $productId1 = $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60
        $productId2 = $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);               // orderCount=1, totalRevenue=20
        $productId3 = $this->createProductWithOrderItems($this->makeProduct('Product 3'));                        // orderCount=0, totalRevenue=0
        $productId4 = $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);        // orderCount=2, totalRevenue=40

        $this->createReview($productId1, 'Review 1', rand(1, 5));
        $this->createReview($productId2, 'Review 2', rand(1, 5));
        $this->createReview($productId3, 'Review 3', rand(1, 5));
        $this->createReview($productId4, 'Review 4', rand(1, 5));

        // AVG totalRevenue = (60+20+0+40)/4 = 30

        /** @var array $result */
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue ' .
            'FROM ' . FormulaJoinedProduct::class . ' p ' .
            'JOIN ' . Review::class . ' r WITH r.product = p ' .
            '    AND p.totalRevenue > (SELECT AVG(p2.totalRevenue) FROM ' . FormulaJoinedProduct::class . ' p2) ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // The totalRevenue field formula appears twice: once in SELECT, once in AVG subquery
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        // Product 1: totalRevenue=60 > AVG(30) → ok
        // Product 4: totalRevenue=40 > AVG(30) → ok
        // Product 2: totalRevenue=20 ≤ AVG(30) → filtered out
        // Product 3: totalRevenue=0  ≤ AVG(30) → filtered out
        self::assertCount(2, $result);

        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);

        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);
    }

    /**
     * Test eager loading of a single entity with a formula field using DQL
     */
    public function testRelationDqlEagerLoadSingleEntity(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Reviewed Product'), [35.00, 45.00]);
        $reviewId = $this->createReview($productId, 'Test review', rand(1, 5));

        $found = $this->em->createQuery('SELECT r, p FROM ' . Review::class . ' r JOIN r.product p WHERE r.id = :id')
            ->setParameter('id', $reviewId)
            ->getSingleResult();

        // Exactly 1 query — all formula substitutions in one SQL
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

    public function testDqlScalarSubqueryInSelect(): void
    {
        $this->createProductWithOrderItems($this->makeProduct('Product 1'), [10.00, 20.00, 30.00]);  // totalRevenue=60
        $this->createProductWithOrderItems($this->makeProduct('Product 2'), [20.00]);                // totalRevenue=20
        $this->createProductWithOrderItems($this->makeProduct('Product 3'));                         // totalRevenue=0
        $this->createProductWithOrderItems($this->makeProduct('Product 4'), [10.00, 30.00]);         // totalRevenue=40

        // AVG = (60+20+0+40)/4 = 30
        $result = $this->em->createQuery(
            'SELECT p.name, p.totalRevenue, ' .
            '(SELECT AVG(p2.totalRevenue) FROM ' . FormulaJoinedProduct::class . ' p2) as avgRevenue ' .
            'FROM ' . FormulaJoinedProduct::class . ' p ' .
            'ORDER BY p.totalRevenue DESC'
        )
            ->getResult();

        // Exactly 1 query — all formula substitutions in one SQL
        self::assertCount(1, $this->queryLogger->getQueries());

        $mainSql = $this->queryLogger->getQueries()[0];

        $formulaTotalRevenue = $this->registry->getForProperty(FormulaJoinedProduct::class, 'totalRevenue');

        // Formula appears twice: once for p.totalRevenue in outer SELECT, once for p2.totalRevenue in subquery
        self::assertCountFormulaSubqueries(2, $mainSql, $formulaTotalRevenue);

        self::assertCount(4, $result);

        // AVG = 30.0 for all rows
        foreach ($result as $row) {
            self::assertSame(30.0, $row['avgRevenue']);
        }

        // Sorted by totalRevenue DESC
        self::assertSame('Product 1', $result[0]['name']);
        self::assertSame(60.0, $result[0]['totalRevenue']);

        self::assertSame('Product 4', $result[1]['name']);
        self::assertSame(40.0, $result[1]['totalRevenue']);

        self::assertSame('Product 2', $result[2]['name']);
        self::assertSame(20.0, $result[2]['totalRevenue']);

        self::assertSame('Product 3', $result[3]['name']);
        self::assertSame(0.0, $result[3]['totalRevenue']);
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
     * Test eager loading of a single entity with a relation to a formula entity.
     * For merged entities, loading occurs in 2 requests, but immediately, without accessing the related entity.
     */
    public function testFindRelationEntityEagerLoadSingleEntity(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Rating Product'), [40.00, 45.00]);
        $this->createManyReviews($productId, [3, 4, 5]);

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
}
