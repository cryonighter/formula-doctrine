<?php

namespace Cryonighter\FormulaDoctrine\EventListener;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\FieldMapping;
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
        private FormulaMetadataRegistry $registry,
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
                $fieldMapping = $classMetadata->getFieldMapping($meta->propertyName);

                if ($this->isFormulaField($fieldMapping, $meta)) {
                    continue;
                }

                // Remove metadata if it was not created by us (for example, through the Column attribute)
                unset($classMetadata->fieldMappings[$meta->propertyName]);
            }

            $classMetadata->mapField([
                'fieldName'     => $meta->propertyName,
                'columnName'    => $meta->alias,
                'type'          => $meta->dbalType,
                'nullable'      => $meta->nullable,
                'notInsertable' => true,
                'notUpdatable'  => true,
            ]);
        }
    }

    private function isFormulaField(FieldMapping $fieldMapping, FormulaMetadata $formulaMetadata): bool
    {
        if ($fieldMapping->columnName !== $formulaMetadata->alias) {
            return false;
        }

        if ($fieldMapping->type !== $formulaMetadata->dbalType) {
            return false;
        }

        if ($fieldMapping->nullable !== $formulaMetadata->nullable) {
            return false;
        }

        if ($fieldMapping->notInsertable === false || $fieldMapping->notUpdatable === false) {
            return false;
        }

        return true;
    }
}
