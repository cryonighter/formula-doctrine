<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\DBAL;

use Cryonighter\FormulaDoctrine\DBAL\FormulaConnection;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Statement;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Tests FormulaConnection SQL processing logic in isolation.
 *
 * AbstractConnectionMiddleware requires a real Connection, but since we only
 * test the process() logic (prepare/query/exec delegate to it), we pass a
 * PHPUnit mock of the Connection interface — no real DB needed.
 */
final class FormulaConnectionTest extends TestCase
{
    private FormulaMetadataRegistry $registry;
    private Connection $connectionMock;

    protected function setUp(): void
    {
        $this->registry = new FormulaMetadataRegistry(new FormulaMetadataFactory());
        $this->connectionMock = $this->createMock(Connection::class);
    }

    // --- Fast path: SQL without formula aliases passes through unchanged ---

    public function testSqlWithoutFormulaAliasIsNotModified(): void
    {
        $this->seedRegistry([
            $this->makeMeta('orderCount', '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)'),
        ]);

        $sql = 'SELECT t0.id AS id_1, t0.name AS name_2 FROM customers t0 WHERE t0.id = ?';

        $processed = $this->process($sql);

        self::assertSame($sql, $processed);
    }

    public function testSqlWithoutRootAliasIsNotModified(): void
    {
        $this->seedRegistry([
            $this->makeMeta('orderCount', '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)'),
        ]);

        // No "t0." in the SQL — fast path
        $sql = 'SELECT 1';

        $processed = $this->process($sql);

        self::assertSame($sql, $processed);
    }

    public function testEmptyRegistryLeaveSqlUnchanged(): void
    {
        $sql = 'SELECT t0.id AS id_1, t0.orderCount AS orderCount_2 FROM products t0 WHERE t0.id = ?';

        $processed = $this->process($sql);

        self::assertSame($sql, $processed);
    }

    // --- Formula column replacement ---

    public function testSingleFormulaColumnIsReplaced(): void
    {
        $this->seedRegistry([
            $this->makeMeta('orderCount', '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)'),
        ]);

        $sql = 'SELECT t0.id AS id_1, t0.name AS name_2, t0.orderCount AS orderCount_3 FROM products t0 WHERE t0.id = ?';

        $processed = $this->process($sql);

        self::assertStringContainsString(
            '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = t0.id)',
            $processed,
        );
        self::assertStringNotContainsString('t0.orderCount AS', $processed);
        self::assertStringContainsString('t0.id AS id_1', $processed);
        self::assertStringContainsString('t0.name AS name_2', $processed);
    }

    public function testMultipleFormulaColumnsAreAllReplaced(): void
    {
        $this->seedRegistry([
            $this->makeMeta('orderCount', '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)'),
            $this->makeMeta('totalRevenue', '(SELECT COALESCE(SUM(oi.price), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.customer_id = {this}.id)'),
        ]);

        $sql = 'SELECT t0.id AS id_1, t0.orderCount AS orderCount_2, t0.totalRevenue AS totalRevenue_3 FROM customers t0';

        $processed = $this->process($sql);

        self::assertStringContainsString('SELECT COUNT(*)', $processed);
        self::assertStringContainsString('SELECT COALESCE', $processed);
        self::assertStringNotContainsString('t0.orderCount AS', $processed);
        self::assertStringNotContainsString('t0.totalRevenue AS', $processed);
    }

    // --- {this} placeholder resolution ---

    public function testThisPlaceholderIsReplacedWithRootAlias(): void
    {
        $this->seedRegistry([
            $this->makeMeta('orderCount', '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)'),
        ]);

        $sql = 'SELECT t0.id AS id_1, t0.orderCount AS orderCount_2 FROM customers t0';

        $processed = $this->process($sql);

        self::assertStringContainsString('o.customer_id = t0.id', $processed);
        self::assertStringNotContainsString('{this}', $processed);
    }

    // --- WHERE and JOIN preservation ---

    public function testWhereClauseIsPreservedAfterReplacement(): void
    {
        $this->seedRegistry([
            $this->makeMeta('score', '(SELECT SUM(p.points) FROM points p WHERE p.user_id = {this}.id)'),
        ]);

        $sql = 'SELECT t0.id AS id_1, t0.score AS score_2 FROM users t0 WHERE t0.active = 1 AND t0.id = ?';

        $processed = $this->process($sql);

        self::assertStringContainsString('WHERE t0.active = 1', $processed);
        self::assertStringContainsString('(SELECT SUM(p.points)', $processed);
    }

    public function testNullableFormulaWithCoalesceIsReplaced(): void
    {
        $this->seedRegistry([
            $this->makeMeta('maxPrice', '(SELECT MAX(oi.price) FROM order_items oi WHERE oi.product_id = {this}.id)', nullable: true),
        ]);

        $sql = 'SELECT t0.id AS id_1, t0.maxPrice AS maxPrice_2 FROM products t0';

        $processed = $this->process($sql);

        self::assertStringContainsString('SELECT MAX(oi.price)', $processed);
        self::assertStringNotContainsString('t0.maxPrice AS', $processed);
    }

    // --- Helpers ---

    private function process(string $sql): string
    {
        // We capture the SQL passed to prepare() via the mock
        $capturedSql = null;

        $this->connectionMock
            ->method('prepare')
            ->willReturnCallback(function (string $sql) use (&$capturedSql) {
                $capturedSql = $sql;
                return $this->createMock(Statement::class);
            });

        $connection = new FormulaConnection($this->connectionMock, $this->registry);
        $connection->prepare($sql);

        return $capturedSql ?? $sql;
    }

    /**
     * Seeds FormulaMetadataRegistry with given metadata by bypassing Reflection
     * (inline entity classes have no #[Formula] attributes).
     *
     * @param array<FormulaMetadata> $metadataList
     */
    private function seedRegistry(array $metadataList): void
    {
        $prop = new ReflectionProperty(FormulaMetadataRegistry::class, 'metadata');
        $scanned = new ReflectionProperty(FormulaMetadataRegistry::class, 'scanned');

        $className = 'FakeEntity_' . uniqid();

        $prop->setValue($this->registry, [$className => $metadataList]);
        $scanned->setValue($this->registry, [$className => true]);
    }

    private function makeMeta(
        string $propertyName,
        string $sql,
        string $phpType = 'int',
        string $dbalType = 'integer',
        bool $nullable = false,
        ?string $alias = null,
    ): FormulaMetadata {
        return new FormulaMetadata(
            entityClass: 'FakeEntity',
            propertyName: $propertyName,
            sql: $sql,
            phpType: $phpType,
            dbalType: $dbalType,
            nullable: $nullable,
            alias: $alias ?? $propertyName,
        );
    }
}
