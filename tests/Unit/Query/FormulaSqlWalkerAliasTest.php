<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Query;

use PHPUnit\Framework\TestCase;

/**
 * Tests the isolated string-manipulation logic of FormulaSqlWalker
 * without requiring a real EntityManager or Doctrine infrastructure.
 *
 * SqlWalker cannot be instantiated standalone, so we expose the
 * protected methods under test via an anonymous subclass accessed
 * through a test-specific proxy.
 */
final class FormulaSqlWalkerAliasTest extends TestCase
{
    private FormulaSqlWalkerProxy $walker;

    protected function setUp(): void
    {
        $this->walker = new FormulaSqlWalkerProxy();
    }

    // --- resolvePlaceholder ---

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

    // --- injectBeforeFrom ---

    public function testInjectBeforeFromAppendsToSelectClause(): void
    {
        $originalSql = 'SELECT p0_.id, p0_.name FROM products p0_';
        $expressions = '(SELECT COUNT(*) FROM orders o WHERE o.product_id = p0_.id) AS orderCount';

        $result = $this->walker->publicInjectBeforeFrom($originalSql, $expressions);

        self::assertSame(
            'SELECT p0_.id, p0_.name, (SELECT COUNT(*) FROM orders o WHERE o.product_id = p0_.id) AS orderCount FROM products p0_',
            $result,
        );
    }

    public function testInjectBeforeFromWithMultipleExpressions(): void
    {
        $originalSql = 'SELECT p0_.id FROM products p0_';
        $expressions = '(SELECT COUNT(*) FROM a) AS cnt, (SELECT SUM(x) FROM b) AS total';

        $result = $this->walker->publicInjectBeforeFrom($originalSql, $expressions);

        self::assertSame(
            'SELECT p0_.id, (SELECT COUNT(*) FROM a) AS cnt, (SELECT SUM(x) FROM b) AS total FROM products p0_',
            $result,
        );
    }

    public function testInjectBeforeFromIsCaseInsensitiveForFrom(): void
    {
        // Doctrine генерирует uppercase FROM, но проверим robustness
        $lowerSql = 'SELECT t0_.id from users t0_';
        $expressions = '(SELECT 1) AS computed';

        $result = $this->walker->publicInjectBeforeFrom($lowerSql, $expressions);

        self::assertStringContainsString('(SELECT 1) AS computed', $result);
        self::assertStringContainsString(' from users', $result);
    }

    public function testInjectBeforeFromReturnsSqlIntactWhenNoFromClause(): void
    {
        // Edge case: нет FROM — не ломаемся
        $invalidSql = 'SELECT 1';
        $expressions = '(SELECT 2) AS x';

        $result = $this->walker->publicInjectBeforeFrom($invalidSql, $expressions);

        self::assertSame($invalidSql, $result);
    }

    public function testInjectBeforeFromWithWhereAndJoin(): void
    {
        $originalSql = 'SELECT u0_.id, u0_.name FROM users u0_ INNER JOIN roles r1_ ON u0_.role_id = r1_.id WHERE u0_.active = 1';
        $expressions = '(SELECT COUNT(*) FROM sessions s WHERE s.user_id = u0_.id) AS sessionCount';

        $result = $this->walker->publicInjectBeforeFrom($originalSql, $expressions);

        // Формулы должны быть между SELECT-полями и FROM, не после WHERE
        $fromPos = strpos($result, ' FROM ');
        $formulaPos = strpos($result, 'sessionCount');
        $wherePos = strpos($result, 'WHERE');

        self::assertNotFalse($fromPos);
        self::assertNotFalse($formulaPos);
        self::assertLessThan($fromPos, $formulaPos);
        self::assertLessThan($wherePos, $formulaPos);
    }
}
