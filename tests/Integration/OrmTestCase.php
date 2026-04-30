<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\OrderItem;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Product;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Rating;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class OrmTestCase extends TestCase
{
    protected EntityManagerInterface $em;
    protected QueryLogger $queryLogger;

    abstract protected function createEntityManager(QueryLogger $queryLogger): EntityManagerInterface;

    protected function setUp(): void
    {
        $this->queryLogger = new QueryLogger();
        $this->em = $this->createEntityManager($this->queryLogger);
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

    protected function createConnection(DbalConfiguration $configuration): Connection
    {
        return DriverManager::getConnection(
            [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                //'path' => __DIR__ . '/test_db.sqlite' // For debugging
            ],
            $configuration,
        );
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

        $rating = new Rating();
        $rating->product = $product;
        $rating->stars = 0;
        $this->em->persist($rating);

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
