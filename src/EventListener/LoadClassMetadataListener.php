<?php

namespace Cryonighter\FormulaDoctrine\EventListener;

use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\MappingException;

/**
 * Registers formula fields as virtual mapped fields in ClassMetadata.
 *
 * This allows ObjectHydrator to populate them directly via fieldMappings
 * without requiring a custom hydrator or setHydrationMode().
 *
 * Fields are registered as non-insertable, non-updatable mapped fields
 * so Doctrine hydrates them but never writes them to the database.
 */
final readonly class LoadClassMetadataListener
{
    public function __construct(
        private FormulaRegistry $registry,
    ) {}

    /**
     * @throws MappingException
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $classMetadata = $args->getClassMetadata();
        $className = $classMetadata->getName();
        $formulas = $this->registry->getForClass($className);

        foreach ($formulas as $meta) {
            if ($classMetadata->hasField($meta->propertyName)) {
                continue;
            }

            $classMetadata->mapField([
                'fieldName'  => $meta->propertyName,
                'columnName' => $meta->alias,
                'type'       => $meta->dbalType,
                'nullable'   => $meta->nullable,
                'insertable' => false,
                'updatable'  => false,
            ]);
        }
    }
}
