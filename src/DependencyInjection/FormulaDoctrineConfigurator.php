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
 * Registers FormulaObjectHydrator and wires FormulaSqlWalker as the
 * default output walker via default query hints.
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

        // FormulaRegistry is stored here so both FormulaSqlWalker (via hint)
        // and FormulaObjectHydrator (via $this->em->getConfiguration()) can access it
        $configuration->setDefaultQueryHint(
            FormulaSqlWalker::HINT_REGISTRY,
            $this->registry,
        );
    }
}
