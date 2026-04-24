<?php

namespace Cryonighter\FormulaDoctrine\EventListener;

use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\UnitOfWork;

/**
 * Removes #[Formula] fields from the UnitOfWork changeset before flush.
 *
 * Without this listener, Doctrine would attempt to persist formula field
 * values back to the database, causing SQL errors (no such column).
 */
final class OnFlushListener
{
    public function __construct(
        private readonly FormulaRegistry $registry,
    ) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            $this->stripFormulaFields($entity, $uow);
        }

        foreach ($uow->getScheduledEntityInsertions() as $entity) {
            $this->stripFormulaFields($entity, $uow);
        }
    }

    private function stripFormulaFields(object $entity, UnitOfWork $uow): void
    {
        $entityClass = $entity::class;

        if (!$this->registry->hasFormulas($entityClass)) {
            return;
        }

        $changeSet = $uow->getEntityChangeSet($entity);

        if ($changeSet === []) {
            return;
        }

        $hasChanges = false;

        foreach ($this->registry->getForClass($entityClass) as $meta) {
            if (array_key_exists($meta->propertyName, $changeSet)) {
                unset($changeSet[$meta->propertyName]);
                $hasChanges = true;
            }
        }

        if ($hasChanges) {
            // Re-register the stripped changeset back into UnitOfWork
            $uow->clearEntityChangeSet(spl_object_id($entity));

            if ($changeSet !== []) {
                // Recompute with remaining real changes
                $uow->recomputeSingleEntityChangeSet(
                    $em->getClassMetadata($entityClass),
                    $entity,
                );
            }
        }
    }
}
