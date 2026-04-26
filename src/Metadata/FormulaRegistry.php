<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;

/**
 * In-memory registry of formula metadata per entity class.
 * Acts as a session-scoped cache to avoid repeated Reflection calls.
 *
 * Also serves as the communication channel between FormulaSqlWalker
 * and FormulaObjectHydrator: the walker stores the active formula map here,
 * the hydrator reads and clears it.
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
}
