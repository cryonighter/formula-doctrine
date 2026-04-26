<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Metadata;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use PHPUnit\Framework\TestCase;

final class FormulaMetadataFactoryTest extends TestCase
{
    private FormulaMetadataFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new FormulaMetadataFactory();
    }

    public function testReturnsEmptyArrayForEntityWithoutFormulas(): void
    {
        $result = $this->factory->createForClass(EntityWithoutFormulas::class);

        self::assertSame([], $result);
    }

    public function testDetectsFormulaFields(): void
    {
        $result = $this->factory->createForClass(EntityWithFormulas::class);

        self::assertCount(2, $result);
    }

    public function testInfersTypeFromTypeHint(): void
    {
        $result = $this->factory->createForClass(EntityWithFormulas::class);
        $meta = $result[0];

        self::assertSame('int', $meta->phpType);
        self::assertSame('integer', $meta->dbalType);
        self::assertFalse($meta->nullable);
    }

    public function testInfersNullableFromTypeHint(): void
    {
        $result = $this->factory->createForClass(EntityWithFormulas::class);
        $meta = $result[1];

        self::assertSame('float', $meta->phpType);
        self::assertTrue($meta->nullable);
    }

    public function testUsesPropertyNameAsDefaultAlias(): void
    {
        $result = $this->factory->createForClass(EntityWithFormulas::class);

        self::assertSame('orderCount', $result[0]->alias);
    }

    public function testUsesCustomAlias(): void
    {
        $result = $this->factory->createForClass(EntityWithCustomAlias::class);

        self::assertSame('custom_alias', $result[0]->alias);
    }

    public function testFallsBackToStringForUntypedProperty(): void
    {
        $result = $this->factory->createForClass(EntityWithUntypedFormula::class);

        self::assertSame('string', $result[0]->phpType);
        self::assertTrue($result[0]->nullable);
    }
}

// --- Вспомогательные сущности для тестов ---

class EntityWithoutFormulas
{
    public int $id;
    public string $name;
}

class EntityWithFormulas
{
    #[Formula('(SELECT COUNT(*) FROM t)')]
    public int $orderCount = 0;

    #[Formula('(SELECT MAX(price) FROM t)')]
    public ?float $maxPrice = null;
}

class EntityWithCustomAlias
{
    #[Formula('(SELECT 1)', alias: 'custom_alias')]
    public int $computed = 0;
}

class EntityWithUntypedFormula
{
    #[Formula('(SELECT "hello")')]
    public $untypedField;
}
