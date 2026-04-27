<?php

namespace Cryonighter\FormulaDoctrine\EventListener;

use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;

/**
 * Removes #[Formula] fields from the UnitOfWork changeset before flush.
 *
 * Without this, Doctrine would attempt to write formula values to the database,
 * causing SQL errors (no such column).
 */
final readonly class OnFlushListener
{
    public function __construct(
        private FormulaRegistry $registry,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        assert($em instanceof EntityManagerInterface);

        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->stripFormulaFields($entity, $uow, $em);
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->stripFormulaFields($entity, $uow, $em);
        }
    }

    private function stripFormulaFields(
        object $entity,
        UnitOfWork $uow,
        EntityManagerInterface $em,
    ): void {
        $entityClass = $entity::class;

        if (!$this->registry->hasFormulas($entityClass)) {
            return;
        }

        $changeSet = $uow->getEntityChangeSet($entity);

        if ($changeSet === []) {
            return;
        }

        $formulaPropertyNames = array_map(
            static fn($meta) => $meta->propertyName,
            $this->registry->getForClass($entityClass),
        );

        $hasFormulaChanges = false;

        foreach ($formulaPropertyNames as $propertyName) {
            if (array_key_exists($propertyName, $changeSet)) {
                unset($changeSet[$propertyName]);
                $hasFormulaChanges = true;
            }
        }

        if (!$hasFormulaChanges) {
            return;
        }

        // Clear the current changeset for this entity
        $uow->clearEntityChangeSet(spl_object_id($entity));

        if ($changeSet === []) {
            // No real changes left — remove from scheduled updates
            $uow->scheduleForUpdate($entity);
            // Immediately clear the newly scheduled "empty" update
            $uow->clearEntityChangeSet(spl_object_id($entity));

            return;
        }

        // Restore the remaining non-formula changes
        $classMetadata = $em->getClassMetadata($entityClass);
        $uow->recomputeSingleEntityChangeSet($classMetadata, $entity);
    }
}
