<?php

namespace Cryonighter\FormulaDoctrine\EventListener;

use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;

/**
 * Warms up FormulaRegistry by scanning entity classes as Doctrine loads their metadata.
 * This avoids cold Reflection hits during actual query execution.
 */
final readonly class LoadClassMetadataListener
{
    public function __construct(
        private FormulaRegistry $registry,
    ) {}

    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $classMetadata = $args->getClassMetadata();

        // Trigger registry scan — result is cached internally
        $this->registry->getForClass($classMetadata->getName());
    }
}
