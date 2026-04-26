<?php

namespace Cryonighter\FormulaDoctrine\DependencyInjection;

use Cryonighter\FormulaDoctrine\Hydration\FormulaObjectHydrator;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Cryonighter\FormulaDoctrine\Query\FormulaSqlWalker;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Query;

/**
 * Service Configurator for Doctrine ORM Configuration instances.
 *
 * Called by Symfony DI after each "doctrine.orm.*_configuration" service
 * is instantiated. Injects formula-related defaults so that every DQL
 * query automatically uses FormulaSqlWalker and FormulaObjectHydrator
 * without any user-side boilerplate.
 */
final class FormulaDoctrineConfigurator
{
    public function __construct(
        private readonly FormulaRegistry $registry,
    ) {
    }

    /**
     * Configures a Doctrine ORM Configuration instance.
     * Invoked automatically by the Symfony service container.
     */
    public function configure(Configuration $configuration): void
    {
        // Register the custom hydrator under a stable name
        $configuration->addCustomHydrationMode(
            FormulaObjectHydrator::NAME,
            FormulaObjectHydrator::class,
        );

        // Apply FormulaSqlWalker to every DQL query by default
        $configuration->setDefaultQueryHint(
            Query::HINT_CUSTOM_OUTPUT_WALKER,
            FormulaSqlWalker::class,
        );

        // Pass the registry instance directly — no DI container needed in Walker
        $configuration->setDefaultQueryHint(
            FormulaSqlWalker::HINT_REGISTRY,
            $this->registry,
        );
    }
}
