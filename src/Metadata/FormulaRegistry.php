<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;

/**
 * In-memory registry of formula metadata per entity class.
 * Acts as a session-scoped cache to avoid repeated Reflection calls.
 */
final class FormulaRegistry
{
    /** @var array<class-string, list<FormulaMetadata>> */
    private array $metadata = [];

    /** @var array<class-string, bool> */
    private array $scanned = [];

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
}
