<?php

namespace Cryonighter\FormulaDoctrine\Configuration;

use Cryonighter\FormulaDoctrine\Mapping\ChainingClassMetadataFactory;
use Cryonighter\FormulaDoctrine\Mapping\FormulaDoctrineClassMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Query;

/**
 * Service Configurator for Doctrine ORM Configuration instances.
 *
 * Registers FormulaSqlWalker as the default output walker.
 * If another walker was already registered, it is preserved via
 * HINT_PREVIOUS_WALKER so FormulaSqlWalker can delegate to it (Walker Chaining).
 *
 * Registers FormulaDoctrineClassMetadataFactory as the default class metadata factory.
 * If another factory was already registered, it is preserved via
 * HINT_PREVIOUS_METADATA_FACTORY_NAME so FormulaDoctrineClassMetadataFactory can delegate to it.
 *
 * Sets FormulaMetadataRegistry as a default query hint for FormulaSqlWalker and FormulaDoctrineClassMetadataFactory.
 */
final readonly class FormulaDoctrineConfigurator
{
    public function __construct(
        private FormulaMetadataRegistry $registry,
    ) {
    }

    /**
     * Configures a Doctrine ORM Configuration instance.
     */
    public function configure(Configuration $configuration): void
    {
        $this->configureClassMetadataFactory($configuration);
        $this->configureOutputWalker($configuration);

        // FormulaMetadataRegistry is stored here so both FormulaSqlWalker
        // and FormulaDoctrineClassMetadataFactory (via hint) can access it
        $configuration->setDefaultQueryHint(FormulaSqlWalker::HINT_REGISTRY, $this->registry);
    }

    /**
     * Configures the class metadata factory for Formula Doctrine integration.
     */
    private function configureClassMetadataFactory(Configuration $configuration): void
    {
        // Preserve any previously registered class metadata factory for chaining
        $existingFactoryName = $configuration->getClassMetadataFactoryName();

        if ($existingFactoryName !== '' && $existingFactoryName !== FormulaDoctrineClassMetadataFactory::class) {
            $configuration->setDefaultQueryHint(
                ChainingClassMetadataFactory::HINT_PREVIOUS_METADATA_FACTORY_NAME,
                $existingFactoryName,
            );
        }

        // Apply FormulaDoctrineClassMetadataFactory to every entity by default
        $configuration->setClassMetadataFactoryName(FormulaDoctrineClassMetadataFactory::class);
    }

    /**
     * Configures the output walker for Formula Doctrine integration.
     */
    private function configureOutputWalker(Configuration $configuration): void
    {
        // Preserve any previously registered output walker for chaining
        $existingWalker = $configuration->getDefaultQueryHint(Query::HINT_CUSTOM_OUTPUT_WALKER);

        if (is_string($existingWalker) && $existingWalker !== '' && $existingWalker !== FormulaSqlWalker::class) {
            $configuration->setDefaultQueryHint(FormulaSqlWalker::HINT_PREVIOUS_WALKER, $existingWalker);
        }

        // Apply FormulaSqlWalker to every DQL query by default
        $configuration->setDefaultQueryHint(Query::HINT_CUSTOM_OUTPUT_WALKER, FormulaSqlWalker::class);
    }
}
