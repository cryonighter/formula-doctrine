<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\DBAL\FormulaMiddleware;
use Cryonighter\FormulaDoctrine\DependencyInjection\FormulaDoctrineConfigurator;
use Cryonighter\FormulaDoctrine\EventListener\LoadClassMetadataListener;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\DBAL\Configuration as DbalConfiguration;
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
    protected QueryLogger $queryLogger;

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
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/Fixture/Entity'],
            isDevMode: true,
        );

        // --- Подключаем FormulaDoctrineConfigurator напрямую, без Symfony ---
        $registry = new FormulaRegistry(new FormulaMetadataFactory());
        $configurator = new FormulaDoctrineConfigurator($registry);
        $configurator->configure($ormConfig);

        $this->queryLogger = new QueryLogger();

        $dbalConfig = new DbalConfiguration();
        $dbalConfig->setMiddlewares([
            new FormulaMiddleware($registry),
            $this->queryLogger,
        ]);

        $connection = DriverManager::getConnection(
            [
                'driver' => 'pdo_sqlite',
                'memory' => true,
                //'path' => __DIR__ . '/test_db.sqlite' // For debugging
            ],
            $dbalConfig,
        );

        $em = new EntityManager($connection, $ormConfig);

        $eventManager = $em->getEventManager();

        $eventManager->addEventListener(
            Events::loadClassMetadata,
            new LoadClassMetadataListener($registry),
        );

        return $em;
    }

    private function createSchema(): void
    {
        $schemaTool = new SchemaTool($this->em);
        $metadata = $this->em->getMetadataFactory()->getAllMetadata();
        $schemaTool->createSchema($metadata);

        // Reset counter after schema creation — we don't count DDL
        $this->queryLogger->reset();
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

        $this->queryLogger->reset();
    }
}
