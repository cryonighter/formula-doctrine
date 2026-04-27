<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\DependencyInjection\FormulaDoctrineConfigurator;
use Cryonighter\FormulaDoctrine\EventListener\PostLoadListener;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use PHPUnit\Framework\TestCase;

abstract class OrmTestCase extends TestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->em = $this->createEntityManager();
        $this->createSchema();
    }

    protected function tearDown(): void
    {
        $this->em->close();
        unset($this->em);
    }

    private function createEntityManager(): EntityManagerInterface
    {
        // --- Doctrine ORM конфигурация ---
        $config = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/Fixture/Entity'],
            isDevMode: true,
        );

        // --- Подключаем FormulaDoctrineConfigurator напрямую, без Symfony ---
        $registry = new FormulaRegistry(new FormulaMetadataFactory());
        $configurator = new FormulaDoctrineConfigurator($registry);
        $configurator->configure($config);

        // --- Подключение к SQLite in-memory ---
        $connection = DriverManager::getConnection(
            [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                //'path' => __DIR__ . '/test_db.sqlite' // For debugging
            ],
            $config,
        );

        $em = new EntityManager($connection, $config);

        $eventManager = $em->getEventManager();

        $eventManager->addEventListener(
            Events::postLoad,
            new PostLoadListener($registry),
        );

        return $em;
    }

    private function createSchema(): void
    {
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);
    }

    /**
     * Persists all given entities and flushes.
     */
    protected function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }

        $this->em->flush();
        $this->em->clear();
    }
}
