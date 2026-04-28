<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Metadata;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity\EntityWithCustomAlias;
use Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity\EntityWithFormulas;
use Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity\EntityWithoutFormulas;
use Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity\EntityWithUntypedFormula;
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
