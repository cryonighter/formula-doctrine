<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Query;

use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use ReflectionMethod;

/**
 * Test proxy that exposes FormulaSqlWalker's protected methods
 * without requiring Doctrine infrastructure.
 *
 * Extends FormulaSqlWalker but bypasses its constructor entirely
 * via ReflectionClass::newInstanceWithoutConstructor().
 */
final class FormulaSqlWalkerProxy
{
    private FormulaSqlWalker $walker;

    public function __construct()
    {
        // SqlWalker's constructor requires EntityManager + Query — we skip it entirely.
        // We only need to call the isolated protected methods.
        $this->walker = (new \ReflectionClass(FormulaSqlWalker::class))
            ->newInstanceWithoutConstructor();
    }

    public function publicResolvePlaceholder(string $sql, string $tableAlias): string
    {
        $method = new ReflectionMethod(FormulaSqlWalker::class, 'resolvePlaceholder');

        return $method->invoke($this->walker, $sql, $tableAlias);
    }

    public function publicInjectBeforeFrom(string $sql, string $expressions): string
    {
        $method = new ReflectionMethod(FormulaSqlWalker::class, 'injectBeforeFrom');

        return $method->invoke($this->walker, $sql, $expressions);
    }
}
