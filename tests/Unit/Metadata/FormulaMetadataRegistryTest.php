<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Metadata;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity\EntityWithFormulas;
use Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity\EntityWithoutFormulas;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class FormulaMetadataRegistryTest extends TestCase
{
    private FormulaMetadataRegistry $registry;
    private EntityManagerInterface $emMock;

    protected function setUp(): void
    {
        $this->registry = new FormulaMetadataRegistry(new FormulaMetadataFactory());

        $this->emMock = $this->createMock(EntityManagerInterface::class);
    }

    public function testHasFormulasReturnsFalseForEntityWithout(): void
    {
        $this->registry->createForClass(EntityWithoutFormulas::class, 'some_table', $this->emMock);

        self::assertFalse($this->registry->hasFormulas(EntityWithoutFormulas::class));
    }

    public function testHasFormulasReturnsTrueForEntityWith(): void
    {
        $this->registry->createForClass(EntityWithFormulas::class, 'some_table', $this->emMock);

        self::assertTrue($this->registry->hasFormulas(EntityWithFormulas::class));
    }

    public function testGetForClassReturnsSameInstanceOnSecondCall(): void
    {
        // Should not call Reflection twice — verifies caching
        $first = $this->registry->createForClass(EntityWithFormulas::class, 'some_table', $this->emMock);
        $second = $this->registry->createForClass(EntityWithFormulas::class, 'some_table', $this->emMock);

        self::assertSame($first, $second);
    }

    public function testGetForPropertyReturnsCorrectMeta(): void
    {
        $this->registry->createForClass(EntityWithFormulas::class, 'some_table', $this->emMock);

        $meta = $this->registry->getForProperty(EntityWithFormulas::class, 'orderCount');

        self::assertNotNull($meta);
        self::assertSame('orderCount', $meta->propertyName);
    }

    public function testGetForPropertyReturnsNullForUnknownProperty(): void
    {
        $this->registry->createForClass(EntityWithFormulas::class, 'some_table', $this->emMock);

        $meta = $this->registry->getForProperty(EntityWithFormulas::class, 'nonExistent');

        self::assertNull($meta);
    }
}
