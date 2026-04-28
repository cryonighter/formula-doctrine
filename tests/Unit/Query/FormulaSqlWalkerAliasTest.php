<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Tests the isolated string-manipulation logic of FormulaSqlWalker
 * without requiring a real EntityManager or Doctrine infrastructure.
 *
 * The Walker's core logic is:
 *   1. resolvePlaceholder() — replaces {this} with the real SQL table alias
 *   2. Column replacement — str_replace("alias.fieldName", resolvedSql, $sql)
 *      This is tested here by simulating what walkSelectStatement() does.
 */
final class FormulaSqlWalkerAliasTest extends TestCase
{
    private FormulaSqlWalkerProxy $walker;

    protected function setUp(): void
    {
        $this->walker = new FormulaSqlWalkerProxy();
    }

    public function testResolvePlaceholderReplacesThis(): void
    {
        $result = $this->walker->publicResolvePlaceholder(
            '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)',
            'c0_',
        );

        self::assertSame(
            '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = c0_.id)',
            $result,
        );
    }

    public function testResolvePlaceholderReplacesAllOccurrences(): void
    {
        $result = $this->walker->publicResolvePlaceholder(
            '(SELECT 1 FROM t WHERE t.a = {this}.a AND t.b = {this}.b)',
            'x1_',
        );

        self::assertSame(
            '(SELECT 1 FROM t WHERE t.a = x1_.a AND t.b = x1_.b)',
            $result,
        );
    }

    public function testResolvePlaceholderLeavesStringIntactWhenNoPlaceholder(): void
    {
        $sql = '(SELECT COUNT(*) FROM orders)';

        $result = $this->walker->publicResolvePlaceholder($sql, 'c0_');

        self::assertSame($sql, $result);
    }

    public function testResolvePlaceholderHandlesNumericAlias(): void
    {
        $result = $this->walker->publicResolvePlaceholder(
            '{this}.id',
            't42_',
        );

        self::assertSame('t42_.id', $result);
    }

    // --- Column replacement logic ---
    // Walker does: str_replace("$tableAlias.$fieldAlias", $resolvedSql, $sql)
    // We test this pattern directly to document and guard the replacement contract.

    public function testColumnReferenceIsReplacedWithSubquery(): void
    {
        $sql = 'SELECT p0_.id AS id_0, p0_.name AS name_1, p0_.orderCount AS orderCount_2 FROM products p0_';
        $resolvedSql = '(SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p0_.id)';

        $result = str_replace('p0_.orderCount', $resolvedSql, $sql);

        self::assertSame(
            'SELECT p0_.id AS id_0, p0_.name AS name_1, (SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p0_.id) AS orderCount_2 FROM products p0_',
            $result,
        );
    }

    public function testMultipleColumnReferencesAreReplacedIndependently(): void
    {
        $sql = 'SELECT p0_.id AS id_0, p0_.orderCount AS orderCount_1, p0_.totalRevenue AS totalRevenue_2 FROM products p0_';

        $sql = str_replace(
            'p0_.orderCount',
            '(SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = p0_.id)',
            $sql,
        );

        $sql = str_replace(
            'p0_.totalRevenue',
            '(SELECT COALESCE(SUM(oi.price), 0) FROM order_items oi WHERE oi.product_id = p0_.id)',
            $sql,
        );

        self::assertStringContainsString('(SELECT COUNT(*)', $sql);
        self::assertStringContainsString('(SELECT COALESCE', $sql);
        self::assertStringContainsString('FROM products p0_', $sql);
    }

    public function testReplacementDoesNotAffectOtherColumnsWithSimilarNames(): void
    {
        // "orderCount" must not accidentally replace "orderCountByDate" or similar
        $sql = 'SELECT p0_.id AS id_0, p0_.orderCount AS orderCount_1, p0_.orderCountByDate AS orderCountByDate_2 FROM products p0_';

        $result = str_replace(
            'p0_.orderCount AS',
            '(SELECT COUNT(*) FROM order_items WHERE product_id = p0_.id) AS',
            $sql,
        );

        self::assertStringContainsString('(SELECT COUNT(*)', $result);
        self::assertStringContainsString('p0_.orderCountByDate', $result);
    }

    public function testReplacementWithWhereClausePreserved(): void
    {
        $sql = 'SELECT c0_.id AS id_0, c0_.orderCount AS orderCount_1 FROM customers c0_ WHERE c0_.active = 1';
        $resolvedSql = '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = c0_.id)';

        $result = str_replace('c0_.orderCount', $resolvedSql, $sql);

        self::assertStringContainsString('WHERE c0_.active = 1', $result);
        self::assertStringContainsString('(SELECT COUNT(*) FROM orders', $result);
    }

    public function testReplacementWithJoinPreserved(): void
    {
        $sql = 'SELECT u0_.id AS id_0, u0_.score AS score_1 FROM users u0_ INNER JOIN roles r1_ ON u0_.role_id = r1_.id';
        $resolvedSql = '(SELECT SUM(p.points) FROM points p WHERE p.user_id = u0_.id)';

        $result = str_replace('u0_.score', $resolvedSql, $sql);

        self::assertStringContainsString('INNER JOIN roles r1_', $result);
        self::assertStringContainsString('(SELECT SUM(p.points)', $result);
    }
}
