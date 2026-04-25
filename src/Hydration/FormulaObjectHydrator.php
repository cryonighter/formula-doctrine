<?php

namespace Cryonighter\FormulaDoctrine\Hydration;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use ReflectionProperty;

/**
 * Extends ObjectHydrator to populate #[Formula] fields after standard hydration.
 *
 * Strategy:
 *   1. FormulaSqlWalker registers formula columns via ResultSetMapping::addScalarResult.
 *   2. Doctrine's standard hydration sees them as scalars and returns a "mixed" result:
 *      [ [0 => Entity, 'alias' => value], ... ]
 *   3. We intercept hydrateAllData(), extract entities and their formula scalars,
 *      write formula values via Reflection, and return a clean array of entities.
 */
final class FormulaObjectHydrator extends ObjectHydrator
{
    public const NAME = 'formula_object';

    /**
     * @var array<class-string, array<string, ReflectionProperty>>
     */
    private array $reflectionCache = [];

    public function hydrateAllData(): array
    {
        // Parent returns mixed result: [[0 => Entity, 'formulaAlias' => value], ...]
        // when both entity columns and scalar columns are in SELECT.
        $rawResult = parent::hydrateAllData();

        /** @var array<string, FormulaMetadata> $formulaMap */
        $formulaMap = $this->_query->getHint(FormulaSqlWalker::HINT_FORMULA_MAP);

        if (!is_array($formulaMap) || $formulaMap === []) {
            return $rawResult;
        }

        $result = [];

        foreach ($rawResult as $row) {
            // Mixed result row: numeric key 0 → entity object, string keys → scalars
            if (!is_array($row)) {
                // Pure result (no scalars registered) — should not happen, but be safe
                $result[] = $row;
                continue;
            }

            $entity = $row[0] ?? null;

            if (!is_object($entity)) {
                $result[] = $row;
                continue;
            }

            foreach ($formulaMap as $alias => $meta) {
                if (!array_key_exists($alias, $row)) {
                    continue;
                }

                $value = $this->castValue($row[$alias], $meta);
                $this->setPropertyValue($entity, $meta, $value);
            }

            $result[] = $entity;
        }

        return $result;
    }
    /**
     * Casts a raw DB value to the target PHP type defined in FormulaMetadata.
     */
    private function castValue(mixed $rawValue, FormulaMetadata $meta): mixed
    {
        if ($rawValue === null) {
            return null;
        }

        return match ($meta->phpType) {
            'int', 'integer' => (int) $rawValue,
            'float', 'double' => (float) $rawValue,
            'bool', 'boolean' => (bool) $rawValue,
            default => (string) $rawValue,
        };
    }

    /**
     * Uses ReflectionProperty to bypass readonly and visibility constraints.
     */
    private function setPropertyValue(object $entity, FormulaMetadata $meta, mixed $value): void
    {
        $prop = $this->getCachedReflectionProperty($entity::class, $meta->propertyName);
        $prop->setValue($entity, $value);
    }

    private function getCachedReflectionProperty(string $entityClass, string $propertyName): ReflectionProperty
    {
        if (!isset($this->reflectionCache[$entityClass][$propertyName])) {
            $prop = new ReflectionProperty($entityClass, $propertyName);
            $prop->setAccessible(true);
            $this->reflectionCache[$entityClass][$propertyName] = $prop;
        }

        return $this->reflectionCache[$entityClass][$propertyName];
    }
}
