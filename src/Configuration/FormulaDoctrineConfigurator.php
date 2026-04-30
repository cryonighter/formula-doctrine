<?php

namespace Cryonighter\FormulaDoctrine\Configuration;

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
        // Preserve any previously registered output walker for chaining
        $existingWalker = $configuration->getDefaultQueryHint(Query::HINT_CUSTOM_OUTPUT_WALKER);

        if (is_string($existingWalker) && $existingWalker !== '' && $existingWalker !== FormulaSqlWalker::class) {
            $configuration->setDefaultQueryHint(
                FormulaSqlWalker::HINT_PREVIOUS_WALKER,
                $existingWalker,
            );
        }

        // Apply FormulaSqlWalker to every DQL query by default
        $configuration->setDefaultQueryHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            FormulaSqlWalker::class,
        );

        // FormulaMetadataRegistry is stored here so both FormulaSqlWalker (via hint) can access it
        $configuration->setDefaultQueryHint(
            FormulaSqlWalker::HINT_REGISTRY,
            $this->registry,
        );
    }
}
