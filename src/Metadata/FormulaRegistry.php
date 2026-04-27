<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;

/**
 * In-memory registry of formula metadata per entity class.
 * Acts as a session-scoped cache to avoid repeated Reflection calls.
 *
 * Also serves as the communication channel between FormulaSqlWalker,
 * FormulaObjectHydrator and PostLoadListener:
 * - Walker stores the active formula map here before hydration
 * - Hydrator reads it, clears it, and marks each entity as hydrated
 * - PostLoadListener checks the flag and skips already-hydrated entities
 */
final class FormulaRegistry
{
    /** @var array<class-string, list<FormulaMetadata>> */
    private array $metadata = [];

    /** @var array<class-string, bool> */
    private array $scanned = [];

    /**
     * Active formula map for the current query, set by FormulaSqlWalker.
     * Set by FormulaSqlWalker, read by FormulaObjectHydrator.
     * Format: alias → FormulaMetadata
     *
     * @var array<string, FormulaMetadata>
     */
    private array $activeFormulaMap = [];

    /**
     * Tracks which entity instances have already been hydrated by FormulaObjectHydrator.
     * Key: spl_object_id($entity)
     *
     * @var array<int, bool>
     */
    private array $hydratedObjects = [];

    public function __construct(
        private readonly FormulaMetadataFactory $factory,
    ) {}

    /**
     * @param class-string $className
     * @return list<FormulaMetadata>
     */
    public function getForClass(string $className): array
    {
        if (!isset($this->scanned[$className])) {
            $this->metadata[$className] = $this->factory->createForClass($className);
            $this->scanned[$className] = true;
        }

        return $this->metadata[$className] ?? [];
    }

    /** @param class-string $className */
    public function hasFormulas(string $className): bool
    {
        return $this->getForClass($className) !== [];
    }

    /** @param class-string $className */
    public function getForProperty(string $className, string $propertyName): ?FormulaMetadata
    {
        foreach ($this->getForClass($className) as $meta) {
            if ($meta->propertyName === $propertyName) {
                return $meta;
            }
        }

        return null;
    }

    /**
     * Called by FormulaSqlWalker after resolving formulas for the current query.
     * Stores the map so FormulaObjectHydrator can read it without touching $_query.
     *
     * @param array<string, FormulaMetadata> $formulaMap
     */
    public function setActiveFormulaMap(array $formulaMap): void
    {
        $this->activeFormulaMap = $formulaMap;
    }

    /**
     * Called by FormulaObjectHydrator to read the active formula map.
     *
     * @return array<string, FormulaMetadata>
     */
    public function getActiveFormulaMap(): array
    {
        return $this->activeFormulaMap;
    }

    /**
     * Clears the active formula map. Called by FormulaObjectHydrator after hydration.
     */
    public function clearActiveFormulaMap(): void
    {
        $this->activeFormulaMap = [];
    }

    /**
     * Marks an entity instance as already hydrated by FormulaObjectHydrator.
     * PostLoadListener checks this to avoid duplicate SQL.
     */
    public function markAsHydrated(object $entity): void
    {
        $this->hydratedObjects[spl_object_id($entity)] = true;
    }

    /**
     * Returns true if this entity instance was already hydrated by FormulaObjectHydrator.
     */
    public function isHydrated(object $entity): bool
    {
        return isset($this->hydratedObjects[spl_object_id($entity)]);
    }

    /**
     * Removes the hydration mark for an entity instance.
     * Called when the entity is detached or the EM is cleared.
     */
    public function unmarkAsHydrated(object $entity): void
    {
        unset($this->hydratedObjects[spl_object_id($entity)]);
    }
}
