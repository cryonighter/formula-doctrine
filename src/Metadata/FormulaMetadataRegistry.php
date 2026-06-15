<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Doctrine\ORM\EntityManagerInterface;

/**
 * In-memory registry of formula metadata per entity class.
 * Acts as a session-scoped cache to avoid repeated Reflection calls.
 *
 * @final
 * @internal
 */
class FormulaMetadataRegistry
{
    /**
     * @var array<class-string, array<FormulaMetadata>>
     */
    private array $metadata = [];

    /**
     * @var array<class-string, bool>
     */
    private array $scanned = [];

    /**
     * @var array<class-string, string>
     */
    private array $tableNames = [];

    public function __construct(
        private readonly FormulaMetadataFactory $factory,
    ) {}

    /**
     * Returns all formula metadata for a given class.
     *
     * @return array<FormulaMetadata>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /**
     * Returns all class names that have been scanned so far.
     * Used by FormulaConnection to find relevant formula metadata.
     *
     * @return array<class-string>
     */
    public function getScannedClasses(): array
    {
        return array_keys($this->scanned);
    }

    public function createForClass(string $className, string $tableName, EntityManagerInterface $em): array
    {
        if (!isset($this->scanned[$className])) {
            $meta = $this->factory->createForClass($className, $tableName, $em);

            if ($meta) {
                $this->metadata[$className] = $meta;
            }

            $this->tableNames[$className] = $tableName;

            $this->scanned[$className] = true;
        }

        return $this->metadata[$className] ?? [];
    }

    /**
     * Returns all formula metadata for a given class.
     *
     * @param string $className
     *
     * @return array<FormulaMetadata>
     */
    public function getForClass(string $className): array
    {
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

    /**
     * Returns the table name associated with a given class.
     */
    public function getTableNameForClass(string $className): ?string
    {
        return $this->tableNames[$className] ?? null;
    }
}
