<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Configuration\FormulaDoctrineConfigurator;
use Cryonighter\FormulaDoctrine\DBAL\FormulaMiddleware;
use Cryonighter\FormulaDoctrine\EventListener\LoadClassMetadataListener;
use Cryonighter\FormulaDoctrine\EventListener\PostGenerateSchemaListener;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Rating;
use Cryonighter\FormulaDoctrine\Tests\Integration\Middleware\QueryLogger;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class OrmTestCase extends TestCase
{
    protected FormulaMetadataRegistry $registry;
    protected EntityManagerInterface $em;
    protected QueryLogger $queryLogger;

    public static function assertCountFormulaSubqueries(
        int $expectedCount,
        string $mainQuery,
        FormulaMetadata $formulaMetadata,
    ): void {
        $subQuery = strstr($formulaMetadata->sql, '{this}', true) ?: $formulaMetadata->sql;

        $subQueryCount = substr_count($mainQuery, $subQuery);

        $message = "Expected $expectedCount subqueries, found $subQueryCount instead";

        self::assertSame($expectedCount, $subQueryCount, $message);
    }

    protected function setUp(): void
    {
        $this->registry = new FormulaMetadataRegistry(new FormulaMetadataFactory());
        $this->queryLogger = new QueryLogger();
        $this->em = $this->createEntityManager($this->queryLogger, false);
        $this->createSchema();
    }

    protected function createSchema(): void
    {
        $schemaTool = new SchemaTool($this->em);
        $schemaTool->createSchema($this->em->getMetadataFactory()->getAllMetadata());

        // Reset counter after schema creation — we don't count DDL
        $this->queryLogger->reset();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        unset($this->em);
    }

    protected function createEntityManager(QueryLogger $queryLogger, bool $useCache): EntityManagerInterface
    {
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/Fixture/Entity'],
            isDevMode: !$useCache, // In prod mode, the cache works more actively
        );

        $this->setDefaultQueryHint($ormConfig);

        if ($useCache) {
            $ormConfig->setMetadataCache(
                new FilesystemAdapter(
                    namespace: 'doctrine_metadata',
                    defaultLifetime: 0,
                    directory: __DIR__ . '/cache/doctrine'
                ),
            );

            $ormConfig->setQueryCache(
                new FilesystemAdapter(
                    namespace: 'doctrine_queries',
                    defaultLifetime: 0,
                    directory: __DIR__ . '/cache/doctrine'
                ),
            );
        }

        // Connecting FormulaDoctrineConfigurator directly, without Symfony
        $configurator = new FormulaDoctrineConfigurator($this->registry);
        $configurator->configure($ormConfig);

        $dbalConfig = new DbalConfiguration();
        $dbalConfig->setMiddlewares([
            $queryLogger, // Must be first middleware
            new FormulaMiddleware($this->registry),
        ]);

        $em = new EntityManager(
            $this->createConnection($dbalConfig),
            $ormConfig,
        );

        $eventManager = $em->getEventManager();

        $eventManager->addEventListener(
            Events::loadClassMetadata,
            new LoadClassMetadataListener($this->registry),
        );

        $eventManager->addEventListener(
            'postGenerateSchema',
            new PostGenerateSchemaListener($this->registry),
        );

        return $em;
    }

    protected function createConnection(DbalConfiguration $configuration): Connection
    {
        return DriverManager::getConnection(
            [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                //'path' => __DIR__ . '/test_db.sqlite', // For debugging
            ],
            $configuration,
        );
    }

    protected function setDefaultQueryHint(DbalConfiguration $ormConfig): void
    {
        // No-op
    }

    /**
     * Helper method to create a product entity
     */
    protected function makeProduct(string $name): Product
    {
        $product = new Product();
        $product->name = $name;

        return $product;
    }

    /**
     * Helper method to create an order item entity
     */
    protected function makeOrderItem(Product $product, float $price): OrderItem
    {
        $item = new OrderItem();
        $item->product = $product;
        $item->price = (string) $price;
        $item->quantity = 1;

        return $item;
    }

    /**
     * Helper method to persist product and order items for him
     */
    protected function createProductWithOrderItems(Product $product, array $prices = []): int
    {
        $this->em->persist($product);
        $this->em->persist(new Rating($product));

        foreach ($prices as $price) {
            $this->em->persist($this->makeOrderItem($product, $price));
        }

        $this->em->flush();
        $this->em->clear();

        $this->queryLogger->reset();

        return $product->id;
    }

    /**
     * Helper method for load a product by ID via DQL
     */
    protected function getProduct(int $id): Product
    {
        $product = $this->em
            ->createQuery('SELECT p FROM ' . Product::class . ' p WHERE p.id = :id')
            ->setParameter('id', $id)
            ->getSingleResult();

        self::assertInstanceOf(Product::class, $product);

        return $product;
    }
}
