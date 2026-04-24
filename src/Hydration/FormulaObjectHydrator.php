<?php

namespace Cryonighter\FormulaDoctrine\Hydration;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Doctrine\ORM\Internal\Hydration\ObjectHydrator;
use ReflectionProperty;

/**
 * Extends the default ObjectHydrator to populate #[Formula] fields
 * from scalar columns injected into the SELECT clause by FormulaSqlWalker.
 *
 * Must be registered in Doctrine configuration:
 *   doctrine.orm.hydrators.formula_object: FormulaObjectHydrator::class
 *
 * And used explicitly or set as default via an event listener.
 */
final class FormulaObjectHydrator extends ObjectHydrator
{
    public const NAME = 'formula_object';

    /**
     * @var array<class-string, list<FormulaMetadata>>
     */
    private array $formulaMetaCache = [];

    /**
     * @var array<class-string, array<string, ReflectionProperty>>
     */
    private array $reflectionCache = [];

    protected function hydrateRowData(array $row, array &$result): void
    {
        // Let the parent hydrate the entity object first
        parent::hydrateRowData($row, $result);

        $registry = $this->_query->getHint(FormulaSqlWalker::HINT_REGISTRY);

        if (!$registry instanceof FormulaRegistry) {
            return;
        }

        // The last element added to $result is the freshly hydrated entity
        $entity = $this->resolveLastEntity($result);

        if ($entity === null) {
            return;
        }

        $entityClass = $entity::class;
        $formulas = $this->getCachedFormulas($registry, $entityClass);

        if ($formulas === []) {
            return;
        }

        foreach ($formulas as $meta) {
            // Formula aliases come back as plain lowercase keys in $row
            $columnKey = strtolower($meta->alias);

            if (!array_key_exists($columnKey, $row)) {
                continue;
            }

            $rawValue = $row[$columnKey];
            $value = $this->castValue($rawValue, $meta);

            $this->setPropertyValue($entity, $meta, $value);
        }
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

    /**
     * @param class-string $entityClass
     * @return list<FormulaMetadata>
     */
    private function getCachedFormulas(FormulaRegistry $registry, string $entityClass): array
    {
        if (!array_key_exists($entityClass, $this->formulaMetaCache)) {
            $this->formulaMetaCache[$entityClass] = $registry->getForClass($entityClass);
        }

        return $this->formulaMetaCache[$entityClass];
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

    /**
     * Extracts the last entity object hydrated into the result array.
     * ObjectHydrator stores results as indexed arrays; the last entry is current.
     */
    private function resolveLastEntity(array $result): ?object
    {
        if ($result === []) {
            return null;
        }

        $last = end($result);

        return is_object($last) ? $last : null;
    }
}
