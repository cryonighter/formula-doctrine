<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Mapping\Mock;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver;
use Doctrine\Persistence\Mapping\ReflectionService;
use RuntimeException;

/**
 * Mock class for testing factory chaining.
 *
 * This class simulates the behavior of a real metadata factory,
 * but instead of loading metadata from annotations/attributes/XML,
 * it uses preset metadata.
 *
 * This allows testing the factory chaining mechanism
 * without needing to create real entity classes.
 */
class MockPreviousMetadataFactory extends AbstractClassMetadataFactory
{
    /**
     * Static storage for preconfiguring the factory.
     * When the factory is created via new, it copies this data to its instance.
     *
     * @var array<string, ClassMetadataInterface>
     */
    private static array $staticPreloadedMetadata = [];

    /**
     * Storage of preset metadata for this instance.
     * Key - class name, value - metadata.
     *
     * @var array<string, ClassMetadataInterface>
     */
    private array $preloadedMetadata = [];

    /**
     * Copies statically preset metadata to the instance.
     */
    public function __construct()
    {
        $this->preloadedMetadata = self::$staticPreloadedMetadata;
    }

    /**
     * Static method for presetting metadata.
     * Used in tests BEFORE creating the factory.
     *
     * @param string $className Class name
     * @param ClassMetadataInterface $metadata Class metadata
     */
    public static function preloadStaticMetadataFor(string $className, ClassMetadataInterface $metadata): void
    {
        self::$staticPreloadedMetadata[$className] = $metadata;
    }

    /**
     * Clears the static storage.
     * Should be called after each test.
     */
    public static function clearStaticMetadata(): void
    {
        self::$staticPreloadedMetadata = [];
    }

    public function setEntityManager(EntityManagerInterface $em): void
    {
        // Not required for mock factory
    }

    public function getMetadataFor(string $className): ClassMetadataInterface
    {
        if (isset($this->preloadedMetadata[$className])) {
            return $this->preloadedMetadata[$className];
        }

        throw new RuntimeException(
            "MockPreviousMetadataFactory: No preloaded metadata for class '$className'. " .
            "Use preloadStaticMetadataFor() before creating the factory."
        );
    }

    public function hasMetadataFor(string $className): bool
    {
        return isset($this->preloadedMetadata[$className]);
    }

    public function getAllMetadata(): array
    {
        return array_values($this->preloadedMetadata);
    }

    public function getLoadedMetadata(): array
    {
        return $this->getAllMetadata();
    }

    public function isTransient(string $className): bool
    {
        return !$this->hasMetadataFor($className);
    }

    // ========== Methods required by AbstractClassMetadataFactory ==========

    protected function doLoadMetadata($class, $parent, $rootEntityFound, array $nonSuperclassParents): void
    {
        // Loading is not required in mock factory, as metadata is preset via preloadStaticMetadataFor()
    }

    protected function initialize(): void
    {
        // Initialization is not required for mock factory
    }

    protected function newClassMetadataInstance($className): ClassMetadataInterface
    {
        return new ClassMetadata($className);
    }

    protected function getDriver(): MappingDriver
    {
        // Return a simple stub driver
        return new class implements MappingDriver
        {
            public function loadMetadataForClass(string $className, ClassMetadataInterface $metadata): void
            {
                // Stub - load nothing
            }

            public function getAllClassNames(): array
            {
                return [];
            }

            public function isTransient($className): bool
            {
                return false;
            }
        };
    }

    protected function wakeupReflection(ClassMetadataInterface $class, ReflectionService $reflService): void
    {
        // Not required for mock factory
    }

    protected function initializeReflection(ClassMetadataInterface $class, ReflectionService $reflService): void
    {
        // Not required for mock factory
    }

    protected function isEntity(ClassMetadataInterface $class): bool
    {
        return true;
    }
}
