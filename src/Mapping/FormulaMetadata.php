<?php

namespace Cryonighter\FormulaDoctrine\Mapping;

/**
 * Immutable value object describing a single formula field on an entity.
 */
final readonly class FormulaMetadata
{
    public function __construct(
        /** Fully-qualified class name of the owning entity */
        public string $entityClass,

        /** PHP property name */
        public string $propertyName,

        /** Raw SQL with {this} placeholder */
        public string $sql,

        /**
         * PHP type name for casting after hydration.
         * Inferred from the property type hint; fallback: 'string'.
         * Possible values: 'int', 'float', 'string', 'bool'.
         */
        public string $phpType,

        /** Whether NULL is a valid hydrated value (inferred from type hint) */
        public bool $nullable,

        /** SQL SELECT alias (used as column name in result set) */
        public string $alias,
    ) {}
}