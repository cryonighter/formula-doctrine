<?php

namespace Cryonighter\FormulaDoctrine\EventListener;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Throwable;

/**
 * Removes formula virtual columns from the generated database schema.
 *
 * Formula fields are registered in ClassMetadata so Doctrine can hydrate them,
 * but they have no physical column in the database — the value comes from
 * a SQL subquery injected by FormulaSqlWalker / FormulaMiddleware.
 *
 * Without this listener, SchemaTool would try to CREATE these columns,
 * causing NOT NULL constraint violations on INSERT (since the column does
 * not exist and the formula value is not provided by the application).
 */
final readonly class PostGenerateSchemaListener
{
    public function __construct(
        private FormulaMetadataRegistry $registry,
    ) {}

    public function postGenerateSchema(GenerateSchemaEventArgs $args): void
    {
        $schema = $args->getSchema();
        $em = $args->getEntityManager();

        foreach ($this->registry->getScannedClasses() as $className) {
            $formulas = $this->registry->getForClass($className);

            if (!$formulas) {
                continue;
            }

            try {
                $classMetadata = $em->getClassMetadata($className);
                $tableName = $classMetadata->getTableName();

                if (!$schema->hasTable($tableName)) {
                    continue;
                }

                $table = $schema->getTable($tableName);

                foreach ($formulas as $meta) {
                    if ($table->hasColumn($meta->alias)) {
                        $table->dropColumn($meta->alias);
                    }
                }
            } catch (Throwable) {
                // Class may not be a managed entity in this EM — skip
                continue;
            }
        }
    }
}
