<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Mapping\Mock;

use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\ProxyClassNameResolver;
use Doctrine\Persistence\Mapping\ReflectionService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Spy class for verifying method calls in tests.
 * Records all setter and getter calls for subsequent verification.
 */
class SpyMetadataFactory extends MockPreviousMetadataFactory
{
    // Flags for setters
    public static bool $setCacheCalled = false;
    public static ?CacheItemPoolInterface $cacheValue = null;

    public static bool $setProxyClassNameResolverCalled = false;
    public static ?ProxyClassNameResolver $resolverValue = null;

    public static bool $setReflectionServiceCalled = false;
    public static ?ReflectionService $reflectionServiceValue = null;

    public static bool $setMetadataForCalled = false;
    public static ?string $metadataClassName = null;
    public static ?ClassMetadataInterface $metadataValue = null;

    // Flags for getters
    public static bool $hasMetadataForCalled = false;
    public static bool $isTransientCalled = false;
    public static bool $getAllMetadataCalled = false;
    public static bool $getLoadedMetadataCalled = false;
    public static bool $getReflectionServiceCalled = false;

    /**
     * Resets all flags.
     */
    public static function reset(): void
    {
        // Reset setter flags
        self::$setCacheCalled = false;
        self::$cacheValue = null;
        self::$setProxyClassNameResolverCalled = false;
        self::$resolverValue = null;
        self::$setReflectionServiceCalled = false;
        self::$reflectionServiceValue = null;
        self::$setMetadataForCalled = false;
        self::$metadataClassName = null;
        self::$metadataValue = null;

        // Reset getter flags
        self::$hasMetadataForCalled = false;
        self::$isTransientCalled = false;
        self::$getAllMetadataCalled = false;
        self::$getLoadedMetadataCalled = false;
        self::$getReflectionServiceCalled = false;
    }

    public function setCache(CacheItemPoolInterface $cache): void
    {
        self::$setCacheCalled = true;
        self::$cacheValue = $cache;

        parent::setCache($cache);
    }

    public function setProxyClassNameResolver(ProxyClassNameResolver $resolver): void
    {
        self::$setProxyClassNameResolverCalled = true;
        self::$resolverValue = $resolver;

        parent::setProxyClassNameResolver($resolver);
    }

    public function setReflectionService(ReflectionService $reflectionService): void
    {
        self::$setReflectionServiceCalled = true;
        self::$reflectionServiceValue = $reflectionService;

        parent::setReflectionService($reflectionService);
    }

    public function setMetadataFor(string $className, ClassMetadataInterface $class): void
    {
        self::$setMetadataForCalled = true;
        self::$metadataClassName = $className;
        self::$metadataValue = $class;

        parent::setMetadataFor($className, $class);
    }

    public function hasMetadataFor(string $className): bool
    {
        self::$hasMetadataForCalled = true;

        return parent::hasMetadataFor($className);
    }

    public function isTransient(string $className): bool
    {
        self::$isTransientCalled = true;

        return parent::isTransient($className);
    }

    public function getAllMetadata(): array
    {
        self::$getAllMetadataCalled = true;

        return parent::getAllMetadata();
    }

    public function getLoadedMetadata(): array
    {
        self::$getLoadedMetadataCalled = true;

        return parent::getLoadedMetadata();
    }

    public function getReflectionService(): ReflectionService
    {
        self::$getReflectionServiceCalled = true;

        return parent::getReflectionService();
    }
}
