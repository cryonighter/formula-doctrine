<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Query;

use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use ReflectionClass;
use ReflectionMethod;

/**
 * Test proxy that exposes FormulaSqlWalker's protected methods
 * without requiring Doctrine infrastructure.
 *
 * Bypasses SqlWalker's constructor entirely via newInstanceWithoutConstructor().
 */
final class FormulaSqlWalkerProxy
{
    private FormulaSqlWalker $walker;

    public function __construct()
    {
        $this->walker = (new ReflectionClass(FormulaSqlWalker::class))
            ->newInstanceWithoutConstructor();
    }

    public function publicResolvePlaceholder(string $sql, string $tableAlias): string
    {
        return (new ReflectionMethod(FormulaSqlWalker::class, 'resolvePlaceholder'))
            ->invoke($this->walker, $sql, $tableAlias);
    }
}
