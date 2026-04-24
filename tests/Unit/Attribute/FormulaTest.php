<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Attribute;

use Attribute;
use Cryonighter\FormulaDoctrine\Attribute\Formula;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class FormulaTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $formula = new Formula('(SELECT 1)');

        self::assertSame('(SELECT 1)', $formula->sql);
        self::assertNull($formula->alias);
    }

    public function testCustomAlias(): void
    {
        $formula = new Formula('(SELECT COUNT(*) FROM foo)', alias: 'fooCount');

        self::assertSame('fooCount', $formula->alias);
    }

    public function testIsAttribute(): void
    {
        $reflection = new ReflectionClass(Formula::class);
        $attributes = $reflection->getAttributes(Attribute::class);

        self::assertNotEmpty($attributes);

        /** @var Attribute $attribute */
        $attribute = $attributes[0]->newInstance();
        self::assertSame(Attribute::TARGET_PROPERTY, $attribute->flags);
    }
}
