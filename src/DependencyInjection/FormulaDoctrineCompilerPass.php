<?php

namespace Cryonighter\FormulaDoctrine\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Attaches FormulaDoctrineConfigurator to every "doctrine.orm.*_configuration"
 * service definition found in the container.
 *
 * This is the only place where we touch Doctrine's DI definitions.
 * The actual Doctrine\ORM\Configuration mutation happens at runtime
 * inside FormulaDoctrineConfigurator::configure().
 */
final class FormulaDoctrineCompilerPass implements CompilerPassInterface
{
    private const CONFIGURATOR_SERVICE = 'cryonighter.formula_doctrine.configurator';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(self::CONFIGURATOR_SERVICE)) {
            return;
        }

        $configuratorRef = new Reference(self::CONFIGURATOR_SERVICE);

        foreach ($this->findOrmConfigurationServiceIds($container) as $serviceId) {
            $container
                ->getDefinition($serviceId)
                ->addMethodCall('configure', []) // placeholder — see below
            ;

            // Symfony service configurator syntax:
            // after the service is instantiated, call $configurator->configure($service)
            $container
                ->getDefinition($serviceId)
                ->setConfigurator([$configuratorRef, 'configure'])
            ;
        }
    }

    /**
     * Finds all "doctrine.orm.{name}_configuration" service IDs in the container.
     *
     * @return list<string>
     */
    private function findOrmConfigurationServiceIds(ContainerBuilder $container): array
    {
        $ids = array_filter(
            $container->getServiceIds(),
            static fn(string $id) => (bool) preg_match('/^doctrine\.orm\.\w+_configuration$/', $id),
        );

        return array_values($ids) ?: ['doctrine.orm.default_configuration'];
    }
}
