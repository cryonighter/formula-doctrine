<?php

namespace Cryonighter\FormulaDoctrine\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Attaches FormulaDoctrineConfigurator to every Doctrine ORM Configuration service.
 *
 * Uses setConfigurator() which is called by Symfony DI after the service is
 * instantiated. Only one configurator can be active per service — if another
 * bundle already set one, it would be overwritten.
 *
 * To work around this, FormulaDoctrineBundle must be listed LAST in
 * config/bundles.php among Doctrine-extending bundles. Our CompilerPass runs
 * after others, so setConfigurator() here wins — but FormulaDoctrineConfigurator
 * reads and preserves any previously set HINT_CUSTOM_OUTPUT_WALKER via chaining.
 *
 * Limitation: if another bundle also uses setConfigurator() AND registers AFTER us,
 * their configurator wins and ours is lost. In that case the user must manually
 * call FormulaDoctrineConfigurator::configure() in their bundle configuration.
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
            if (!$container->hasDefinition($serviceId)) {
                continue;
            }

            $container
                ->getDefinition($serviceId)
                ->setConfigurator([$configuratorRef, 'configure'])
            ;
        }
    }

    /**
     * Finds all "doctrine.orm.{name}_configuration" service IDs in the container.
     *
     * @return array<string>
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
