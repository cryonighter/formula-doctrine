<?php

namespace Cryonighter\FormulaDoctrine\Hydration;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use ReflectionProperty;

/**
 * Extends ObjectHydrator to populate #[Formula] fields after standard hydration.
 *
 * FormulaRegistry is retrieved from the Doctrine Configuration default query hints
 * via $this->em — a protected property guaranteed by AbstractHydrator's constructor.
 *
 * FormulaSqlWalker stores the active formula map in FormulaRegistry before hydration.
 * This hydrator reads and clears it, then writes values into entity properties.
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

        $registry = $this->getRegistry();

        if ($registry === null) {
            return $rawResult;
        }

        $formulaMap = $registry->getActiveFormulaMap();
        $registry->clearActiveFormulaMap();

        if ($formulaMap === []) {
            return $rawResult;
        }

        $result = [];

        foreach ($rawResult as $row) {
            // Mixed result row: [0 => Entity, 'alias' => scalarValue, ...]
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

                $this->setPropertyValue($entity, $meta, $this->castValue($row[$alias], $meta));
            }

            // Mark as hydrated so PostLoadListener skips this entity
            $registry->markAsHydrated($entity);

            $result[] = $entity;
        }

        return $result;
    }

    /**
     * Retrieves FormulaRegistry from Doctrine Configuration.
     * Uses $this->em which is a protected property of AbstractHydrator —
     * part of its public constructor contract, safe to rely on.
     */
    private function getRegistry(): ?FormulaRegistry
    {
        $registry = $this->em->getConfiguration()->getDefaultQueryHint(
            FormulaSqlWalker::HINT_REGISTRY,
        );

        return $registry instanceof FormulaRegistry ? $registry : null;
    }

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
        $this->getCachedReflectionProperty($entity::class, $meta->propertyName)
            ->setValue($entity, $value);
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
