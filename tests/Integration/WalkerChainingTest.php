<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\DBAL\FormulaMiddleware;
use Cryonighter\FormulaDoctrine\DependencyInjection\FormulaDoctrineConfigurator;
use Cryonighter\FormulaDoctrine\EventListener\LoadClassMetadataListener;
use Cryonighter\FormulaDoctrine\EventListener\PostGenerateSchemaListener;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Walker\AddCommentSqlWalker;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Query;

/**
 * Tests that FormulaSqlWalker correctly chains with a previously registered
 * third-party OutputWalker, applying both transformations in order:
 *   1. ThirdPartyWalker runs first and generates its SQL
 *   2. FormulaSqlWalker applies formula replacements on top
 */
final class WalkerChainingTest extends OrmTestCase
{
    protected function createEntityManager(QueryLogger $queryLogger): EntityManagerInterface
    {
        $ormConfig = ORMSetup::createAttributeMetadataConfiguration(
            paths: [__DIR__ . '/Fixture/Entity'],
            isDevMode: true,
        );

        // Сначала регистрируем "чужой" Walker — имитируем стороннюю библиотеку
        $ormConfig->setDefaultQueryHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            AddCommentSqlWalker::class,
        );

        // Затем подключаем наш пакет — он должен сохранить AddCommentSqlWalker через chaining
        $registry = new FormulaRegistry(new FormulaMetadataFactory());
        $configurator = new FormulaDoctrineConfigurator($registry);
        $configurator->configure($ormConfig);

        $dbalConfig = new DbalConfiguration();
        $dbalConfig->setMiddlewares([
            new FormulaMiddleware($registry),
            $this->queryLogger,
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

        $eventManager->addEventListener(
            'postGenerateSchema',
            new PostGenerateSchemaListener($registry),
        );

        return $em;
    }

    /**
     * Test that FormulaSqlWalker's HINT_PREVIOUS_WALKER is set to the third-party Walker
     */
    public function testPreviousWalkerHintIsSetInConfiguration(): void
    {
        $hint = $this->em->getConfiguration()
            ->getDefaultQueryHint(FormulaSqlWalker::HINT_PREVIOUS_WALKER);

        self::assertSame(AddCommentSqlWalker::class, $hint);
    }

    /**
     * Test that FormulaSqlWalker is set as the custom output walker
     */
    public function testFormulaSqlWalkerIsActiveOutputWalker(): void
    {
        $hint = $this->em->getConfiguration()
            ->getDefaultQueryHint(Query::HINT_CUSTOM_OUTPUT_WALKER);

        self::assertSame(FormulaSqlWalker::class, $hint);
    }

    public function testSqlWalkerChainingWithFormulaValues(): void
    {
        $productId = $this->createProductWithOrderItems(
            $this->makeProduct('Popular Product'),
            [10.00, 20.00, 30.00],
        );

        $loaded = $this->getProduct($productId);

        $sql = $this->queryLogger->getQueries()[0];

        // Third-party Walker added a comment
        self::assertStringContainsString(AddCommentSqlWalker::COMMENT, $sql);

        // FormulaSqlWalker applied a substitution on top of the third-party Walker result
        self::assertStringContainsString('SELECT COUNT', $sql);
        self::assertStringContainsString('SELECT COALESCE', $sql);
        self::assertStringContainsString('SELECT MAX', $sql);

        // Formula values are correct with chaining
        self::assertSame(3, $loaded->orderCount);
        self::assertEqualsWithDelta(60.00, $loaded->totalRevenue, 0.001);
        self::assertEqualsWithDelta(30.00, $loaded->maxItemPrice, 0.001);
    }

    public function testSqlWalkerChainingWithoutFormulaValues(): void
    {
        $productId = $this->createProductWithOrderItems(
            $this->makeProduct('Empty Product'),
        );

        $loaded = $this->getProduct($productId);

        $sql = $this->queryLogger->getQueries()[0];

        // Third-party Walker added a comment
        self::assertStringContainsString(AddCommentSqlWalker::COMMENT, $sql);

        // FormulaSqlWalker applied a substitution on top of the third-party Walker result
        self::assertStringContainsString('SELECT COUNT', $sql);
        self::assertStringContainsString('SELECT COALESCE', $sql);
        self::assertStringContainsString('SELECT MAX', $sql);

        // Formula values are correct with chaining
        self::assertSame(0, $loaded->orderCount);
        self::assertSame(0.0, $loaded->totalRevenue);
        self::assertNull($loaded->maxItemPrice);
    }
}
