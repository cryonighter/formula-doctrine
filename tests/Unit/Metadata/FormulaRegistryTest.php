<?php

// tests/Unit/Metadata/FormulaRegistryTest.php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Metadata;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use PHPUnit\Framework\TestCase;

final class FormulaRegistryTest extends TestCase
{
    private FormulaRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new FormulaRegistry(new FormulaMetadataFactory());
    }

    public function testHasFormulasReturnsFalseForEntityWithout(): void
    {
        self::assertFalse($this->registry->hasFormulas(EntityWithoutFormulas::class));
    }

    public function testHasFormulasReturnsTrueForEntityWith(): void
    {
        self::assertTrue($this->registry->hasFormulas(EntityWithFormulas::class));
    }

    public function testGetForClassReturnsSameInstanceOnSecondCall(): void
    {
        // Should not call Reflection twice — verifies caching
        $first = $this->registry->getForClass(EntityWithFormulas::class);
        $second = $this->registry->getForClass(EntityWithFormulas::class);

        self::assertSame($first, $second);
    }

    public function testGetForPropertyReturnsCorrectMeta(): void
    {
        $meta = $this->registry->getForProperty(EntityWithFormulas::class, 'orderCount');

        self::assertNotNull($meta);
        self::assertSame('orderCount', $meta->propertyName);
    }

    public function testGetForPropertyReturnsNullForUnknownProperty(): void
    {
        $meta = $this->registry->getForProperty(EntityWithFormulas::class, 'nonExistent');

        self::assertNull($meta);
    }
}
