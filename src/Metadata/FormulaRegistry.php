<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;

/**
 * In-memory registry of formula metadata per entity class.
 * Acts as a session-scoped cache to avoid repeated Reflection calls.
 */
final class FormulaRegistry
{
    /**
     * @var array<class-string, list<FormulaMetadata>>
     */
    private array $metadata = [];

    /**
     * @var array<class-string, bool>
     */
    private array $scanned = [];

    public function __construct(
        private readonly FormulaMetadataFactory $factory,
    ) {}

    /**
     * Returns all class names that have been scanned so far.
     * Used by FormulaConnection to find relevant formula metadata.
     *
     * @return list<class-string>
     */
    public function getScannedClasses(): array
    {
        return array_keys($this->scanned);
    }

    /**
     * Returns all formula metadata for a given class.
     *
     * @param class-string $className
     *
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

    /**
     * Returns true if the given class has any formula metadata.
     *
     * @param string $className
     *
     * @return bool
     */
    public function hasFormulas(string $className): bool
    {
        return (bool) $this->getForClass($className);
    }

    /**
     * Returns formula metadata for a given property within a class.
     *
     * @param string $className
     * @param string $propertyName
     *
     * @return FormulaMetadata|null
     */
    public function getForProperty(string $className, string $propertyName): ?FormulaMetadata
    {
        foreach ($this->getForClass($className) as $meta) {
            if ($meta->propertyName === $propertyName) {
                return $meta;
            }
        }

        return null;
    }
}
