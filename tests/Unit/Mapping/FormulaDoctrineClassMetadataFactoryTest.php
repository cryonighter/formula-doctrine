<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Mapping;

use Cryonighter\FormulaDoctrine\Mapping\ChainingClassMetadataFactory;
use Cryonighter\FormulaDoctrine\Mapping\FormulaDoctrineClassMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Cryonighter\FormulaDoctrine\Tests\Unit\Mapping\Mock\MockPreviousMetadataFactory;
use Cryonighter\FormulaDoctrine\Tests\Unit\Mapping\Mock\SpyMetadataFactory;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\ProxyClassNameResolver;
use Doctrine\Persistence\Mapping\ReflectionService;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Tests the correctness of the metadata factory with chaining support and integration with the formula registry.
 */
class FormulaDoctrineClassMetadataFactoryTest extends TestCase
{
    private Configuration $configuration;
    private EntityManagerInterface $entityManager;
    private FormulaMetadataRegistry $registry;

    /**
     * Prepares mocks for EntityManager, Configuration, and Registry.
     */
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
        $this->configuration = $this->createMock(Configuration::class);
        $this->registry = $this->createMock(FormulaMetadataRegistry::class);

        // Configure the EntityManager mock to return our Configuration mock
        $this->entityManager
            ->method('getConfiguration')
            ->willReturn($this->configuration);
    }

    /**
     * Tests that getMetadataFor() registers the class in the formula registry.
     *
     * The test ensures that:
     * 1. When retrieving class metadata, registry->getForClass() is called
     * 2. The table name is registered via registry->setTableNameForClass()
     * 3. Metadata is returned correctly
     */
    public function testGetMetadataForRegistersClassInRegistry(): void
    {
        $className = 'App\Entity\User';
        $tableName = 'users';

        // Configure without a previous factory
        $this->configuration
            ->method('getDefaultQueryHint')
            ->willReturnMap([
                [FormulaSqlWalker::HINT_REGISTRY, $this->registry],
                [ChainingClassMetadataFactory::HINT_PREVIOUS_METADATA_FACTORY_NAME, null],
            ]);

        // Create a class metadata mock with the specified table name
        $classMetadata = $this->createMock(ClassMetadata::class);
        $classMetadata
            ->method('getTableName')
            ->willReturn($tableName);

        // Expect the registry to receive a request for formula metadata for the class
        $this->registry
            ->expects($this->once())
            ->method('getForClass')
            ->with($className);

        // Expect the table name to be registered for the class
        $this->registry
            ->expects($this->once())
            ->method('setTableNameForClass')
            ->with($className, $tableName);

        // Create and initialize an instance of the tested class
        $factory = new FormulaDoctrineClassMetadataFactory();
        $factory->setEntityManager($this->entityManager);

        // Pre-set metadata in the factory
        // This allows avoiding actual metadata loading
        $factory->setMetadataFor($className, $classMetadata);

        // Call the tested method
        $result = $factory->getMetadataFor($className);

        // Verify that the same metadata is returned
        self::assertSame($classMetadata, $result);
    }

    /**
     * Tests the factory chaining mechanism.
     *
     * The test ensures that:
     * 1. If a previous factory is configured, getMetadataFor() requests are delegated to it
     * 2. After receiving metadata from the previous factory, formula logic is applied
     * 3. Metadata from the previous factory is returned (modified by our logic)
     */
    public function testChainingWithPreviousFactory(): void
    {
        $className = 'App\Entity\Product';
        $tableName = 'products';

        // Create metadata that the previous factory will return
        $previousMetadata = $this->createMock(ClassMetadata::class);
        $previousMetadata
            ->method('getTableName')
            ->willReturn($tableName);

        // IMPORTANT: Configure MockPreviousMetadataFactory BEFORE creating our factory
        // When ChainingClassMetadataFactory creates it via new, it will copy this data
        MockPreviousMetadataFactory::preloadStaticMetadataFor($className, $previousMetadata);

        // Configure with the previous factory class specified
        $this->configuration
            ->method('getDefaultQueryHint')
            ->willReturnMap([
                [FormulaSqlWalker::HINT_REGISTRY, $this->registry],
                [
                    ChainingClassMetadataFactory::HINT_PREVIOUS_METADATA_FACTORY_NAME,
                    MockPreviousMetadataFactory::class,
                ],
            ]);

        // Create our factory
        $factory = new FormulaDoctrineClassMetadataFactory();
        $factory->setEntityManager($this->entityManager);

        // Verify that the registry also receives requests
        $this->registry
            ->expects($this->once())
            ->method('getForClass')
            ->with($className);

        $this->registry
            ->expects($this->once())
            ->method('setTableNameForClass')
            ->with($className, $tableName);

        // Call the tested method
        $result = $factory->getMetadataFor($className);

        // Verify that metadata from the previous factory is returned
        self::assertSame($previousMetadata, $result);

        // Clean up static data after the test
        MockPreviousMetadataFactory::clearStaticMetadata();
    }

    /**
     * Tests that all setters are delegated to the previous factory.
     *
     * The test ensures that:
     * 1. When calling setCache() - cache is set in both current and previous factory
     * 2. When calling setProxyClassNameResolver() - resolver is set in both factories
     * 3. When calling setReflectionService() - service is set in both factories
     * 4. When calling setMetadataFor() - metadata is set in both factories
     */
    public function testSettersDelegationToPreviousFactory(): void
    {
        $testClassName = 'TestClass';

        // Create test objects to set via setters
        $cache = $this->createMock(CacheItemPoolInterface::class);
        $resolver = $this->createMock(ProxyClassNameResolver::class);
        $reflectionService = $this->createMock(ReflectionService::class);
        $metadata = $this->createMock(ClassMetadataInterface::class);

        // Configure with the spy version of the previous factory
        $this->configuration
            ->method('getDefaultQueryHint')
            ->willReturnMap([
                [FormulaSqlWalker::HINT_REGISTRY, $this->registry],
                [
                    ChainingClassMetadataFactory::HINT_PREVIOUS_METADATA_FACTORY_NAME,
                    SpyMetadataFactory::class,
                ],
            ]);

        // Clear spy before the test
        SpyMetadataFactory::reset();

        // Create our factory
        $factory = new FormulaDoctrineClassMetadataFactory();
        $factory->setEntityManager($this->entityManager);

        // Call all setters
        $factory->setCache($cache);
        $factory->setProxyClassNameResolver($resolver);
        $factory->setReflectionService($reflectionService);
        $factory->setMetadataFor($testClassName, $metadata);

        // Verify that all methods were called on the previous factory
        self::assertTrue(SpyMetadataFactory::$setCacheCalled, 'setCache must be called on the previous factory');
        self::assertSame($cache, SpyMetadataFactory::$cacheValue, 'setCache must receive the correct object');

        self::assertTrue(SpyMetadataFactory::$setProxyClassNameResolverCalled, 'setProxyClassNameResolver must be called');
        self::assertSame($resolver, SpyMetadataFactory::$resolverValue, 'setProxyClassNameResolver must receive the correct object');

        self::assertTrue(SpyMetadataFactory::$setReflectionServiceCalled, 'setReflectionService must be called');
        self::assertSame($reflectionService, SpyMetadataFactory::$reflectionServiceValue, 'setReflectionService must receive the correct object');

        self::assertTrue(SpyMetadataFactory::$setMetadataForCalled, 'setMetadataFor must be called');
        self::assertSame($testClassName, SpyMetadataFactory::$metadataClassName, 'setMetadataFor must receive the correct class name');
        self::assertSame($metadata, SpyMetadataFactory::$metadataValue, 'setMetadataFor must receive the correct metadata');

        // Clean up after the test
        SpyMetadataFactory::reset();
    }

    /**
     * Tests that all getters are delegated to the previous factory.
     *
     * The test ensures that:
     * 1. getReflectionService() returns the result from the previous factory
     * 2. getAllMetadata() returns the result from the previous factory
     * 3. getLoadedMetadata() returns the result from the previous factory
     * 4. hasMetadataFor() returns the result from the previous factory
     * 5. isTransient() returns the result from the previous factory
     */
    public function testGettersDelegationToPreviousFactory(): void
    {
        $testClassName = 'TestClass';
        $transientClassName = 'TransientClass';

        // Create test metadata for preloading
        $testMetadata = $this->createMock(ClassMetadata::class);

        // Preload metadata into MockPreviousMetadataFactory
        MockPreviousMetadataFactory::preloadStaticMetadataFor($testClassName, $testMetadata);

        // Configure with the spy version of the previous factory
        $this->configuration
            ->method('getDefaultQueryHint')
            ->willReturnMap([
                [FormulaSqlWalker::HINT_REGISTRY, $this->registry],
                [
                    ChainingClassMetadataFactory::HINT_PREVIOUS_METADATA_FACTORY_NAME,
                    SpyMetadataFactory::class,
                ],
            ]);

        // Clear spy before the test
        SpyMetadataFactory::reset();

        // Create our factory
        $factory = new FormulaDoctrineClassMetadataFactory();
        $factory->setEntityManager($this->entityManager);

        // Test hasMetadataFor - should return true for loaded class
        self::assertTrue($factory->hasMetadataFor($testClassName));
        self::assertTrue(SpyMetadataFactory::$hasMetadataForCalled, 'hasMetadataFor must be called on the previous factory');

        // Test hasMetadataFor - should return false for unloaded class
        SpyMetadataFactory::reset();
        self::assertFalse($factory->hasMetadataFor($transientClassName));
        self::assertTrue(SpyMetadataFactory::$hasMetadataForCalled, 'hasMetadataFor must be called on the previous factory');

        // Test isTransient - should return true for class without metadata
        SpyMetadataFactory::reset();
        self::assertTrue($factory->isTransient($transientClassName));
        self::assertTrue(SpyMetadataFactory::$isTransientCalled, 'isTransient must be called on the previous factory');

        // Test isTransient - should return false for class with metadata
        SpyMetadataFactory::reset();
        self::assertFalse($factory->isTransient($testClassName));
        self::assertTrue(SpyMetadataFactory::$isTransientCalled, 'isTransient must be called on the previous factory');

        // Test getAllMetadata
        SpyMetadataFactory::reset();
        $allMetadata = $factory->getAllMetadata();
        self::assertTrue(SpyMetadataFactory::$getAllMetadataCalled, 'getAllMetadata must be called on the previous factory');
        self::assertIsArray($allMetadata);
        self::assertContains($testMetadata, $allMetadata);

        // Test getLoadedMetadata (alias for getAllMetadata in base implementation)
        SpyMetadataFactory::reset();
        $loadedMetadata = $factory->getLoadedMetadata();
        self::assertTrue(SpyMetadataFactory::$getLoadedMetadataCalled, 'getLoadedMetadata must be called on the previous factory');
        self::assertIsArray($loadedMetadata);

        // Test getReflectionService
        SpyMetadataFactory::reset();
        $reflectionService = $factory->getReflectionService();
        self::assertTrue(SpyMetadataFactory::$getReflectionServiceCalled, 'getReflectionService must be called on the previous factory');
        self::assertNotNull($reflectionService);

        // Clean up after the test
        SpyMetadataFactory::reset();
        MockPreviousMetadataFactory::clearStaticMetadata();
    }
}
