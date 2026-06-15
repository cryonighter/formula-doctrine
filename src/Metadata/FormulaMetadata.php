<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Serializable;

/**
 * Describing a single formula field on an entity
 *
 * @property-read string $sql
 */
final class FormulaMetadata implements Serializable
{
    private string $sql;

    public function __get(string $name): string
    {
        if ($name === 'sql') {
            return $this->resolveSql();
        }

        throw new \RuntimeException('Undefined property: ' . FormulaMetadata::class . "::$name");
    }

    public function __construct(
        /** Fully-qualified class name of the owning entity */
        public string $entityClass,

        /** PHP property name */
        public string $propertyName,

        /** Raw SQL with {this} placeholder */
        public \Closure $sqlResolver,

        /**
         * PHP type name for casting after hydration ('int', 'float', 'string', 'bool').
         * Inferred from the property type hint.
         */
        public string $phpType,

        /**
         * Doctrine DBAL type name ('integer', 'float', 'string', 'boolean').
         * Used in ResultSetMapping::addScalarResult().
         */
        public string $dbalType,

        /** Whether NULL is a valid hydrated value (inferred from type hint) */
        public bool $nullable,

        /** SQL SELECT alias (used as column name in result set) */
        public string $alias,
    ) {}

    public function serialize(): string
    {
        return serialize($this->__serialize());
    }

    public function unserialize(string $data): void
    {
        $this->__unserialize(unserialize($data));
    }

    public function __serialize(): array
    {
        return [
            'entityClass' => $this->entityClass,
            'propertyName' => $this->propertyName,
            'sql' => $this->resolveSql(),
            'phpType' => $this->phpType,
            'dbalType' => $this->dbalType,
            'nullable' => $this->nullable,
            'alias' => $this->alias,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->entityClass = $data['entityClass'];
        $this->propertyName = $data['propertyName'];
        $this->phpType = $data['phpType'];
        $this->dbalType = $data['dbalType'];
        $this->nullable = $data['nullable'];
        $this->alias = $data['alias'];
        $this->sql = $data['sql'];
        $this->sqlResolver = static fn(): string => $data['sql'];
    }

    private function resolveSql(): string
    {
        if (empty($this->sql)) {
            $this->sql = ($this->sqlResolver)();
        }

        return $this->sql;
    }
}
