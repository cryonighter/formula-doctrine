<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Cryonighter\FormulaDoctrine\Query\ChainingFormulaSqlWalker;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Walker\AddCommentSqlWalker;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\ORM\Query;

/**
 * Tests that FormulaSqlWalker correctly chains with a previously registered
 * third-party OutputWalker, applying both transformations in order:
 *   1. ThirdPartyWalker runs first and generates its SQL
 *   2. FormulaSqlWalker applies formula replacements on top
 */
final class WalkerChainingTest extends OrmTestCase
{
    /**
     * Register a "foreign" Walker - we imitate a third-party library
     */
    protected function setDefaultQueryHint(DbalConfiguration $ormConfig): void
    {
        $ormConfig->setDefaultQueryHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            AddCommentSqlWalker::class,
        );
    }

    /**
     * Test that FormulaSqlWalker's HINT_PREVIOUS_WALKER is set to the third-party Walker
     */
    public function testPreviousWalkerHintIsSetInConfiguration(): void
    {
        $hint = $this->em->getConfiguration()
            ->getDefaultQueryHint(ChainingFormulaSqlWalker::HINT_PREVIOUS_WALKER);

        self::assertSame(AddCommentSqlWalker::class, $hint);
    }

    /**
     * Test that FormulaSqlWalker is set as the custom output walker
     */
    public function testFormulaSqlWalkerIsActiveOutputWalker(): void
    {
        $hint = $this->em->getConfiguration()
            ->getDefaultQueryHint(Query::HINT_CUSTOM_OUTPUT_WALKER);

        self::assertSame(ChainingFormulaSqlWalker::class, $hint);
    }

    public function testSqlWalkerChainingWithFormulaValues(): void
    {
        $productId = $this->createProductWithOrderItems($this->makeProduct('Popular Product'), [10.00, 20.00, 30.00]); // orderCount=3, totalRevenue=60

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
        $productId = $this->createProductWithOrderItems($this->makeProduct('Empty Product'));

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
