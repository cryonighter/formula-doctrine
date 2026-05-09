<?php

namespace Cryonighter\FormulaDoctrine\Mapping;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use Doctrine\Persistence\Mapping\AbstractClassMetadataFactory;
use Doctrine\Persistence\Mapping\ClassMetadata as ClassMetadataInterface;
use Doctrine\Persistence\Mapping\MappingException;
use Doctrine\Persistence\Mapping\ProxyClassNameResolver;
use Doctrine\Persistence\Mapping\ReflectionService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * @internal
 */
abstract class ChainingClassMetadataFactory extends ClassMetadataFactory
{
    public const HINT_PREVIOUS_METADATA_FACTORY_NAME = 'formula_doctrine.previous_metadata_factory';

    private ?AbstractClassMetadataFactory $previous = null;

    public function setEntityManager(EntityManagerInterface $em): void
    {
        parent::setEntityManager($em);

        $configuration = $em->getConfiguration();

        $previousClassName = $configuration->getDefaultQueryHint(self::HINT_PREVIOUS_METADATA_FACTORY_NAME);

        if ($previousClassName && is_string($previousClassName) && class_exists($previousClassName)) {
            if ($previousClassName !== ClassMetadataFactory::class) {
                $this->previous = new $previousClassName();
                $this->previous->setEntityManager($em);
            }
        }
    }

    public function getMetadataFor(string $className): ClassMetadata
    {
        return $this->previous
            ? $this->previous->getMetadataFor($className)
            : parent::getMetadataFor($className);
    }

    public function setCache(CacheItemPoolInterface $cache): void
    {
        parent::setCache($cache);

        $this->previous?->setCache($cache);
    }

    public function getLoadedMetadata(): array
    {
        return $this->previous
            ? $this->previous->getLoadedMetadata()
            : parent::getLoadedMetadata();
    }

    public function getAllMetadata(): array
    {
        return $this->previous
            ? $this->previous->getAllMetadata()
            : parent::getAllMetadata();
    }

    public function setProxyClassNameResolver(ProxyClassNameResolver $resolver): void
    {
        parent::setProxyClassNameResolver($resolver);

        $this->previous?->setProxyClassNameResolver($resolver);
    }

    public function hasMetadataFor(string $className): bool
    {
        return $this->previous
            ? $this->previous->hasMetadataFor($className)
            : parent::hasMetadataFor($className);
    }

    public function setMetadataFor(string $className, ClassMetadataInterface $class): void
    {
        parent::setMetadataFor($className, $class);

        $this->previous?->setMetadataFor($className, $class);
    }

    /**
     * @throws MappingException
     */
    public function isTransient(string $className): bool
    {
        return $this->previous
            ? $this->previous->isTransient($className)
            : parent::isTransient($className);
    }

    public function setReflectionService(ReflectionService $reflectionService): void
    {
        parent::setReflectionService($reflectionService);

        $this->previous?->setReflectionService($reflectionService);
    }

    public function getReflectionService(): ReflectionService
    {
        return $this->previous
            ? $this->previous->getReflectionService()
            : parent::getReflectionService();
    }
}
