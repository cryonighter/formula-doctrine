<?php

namespace Cryonighter\FormulaDoctrine\EventListener;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\Event\PostLoadEventArgs;
use ReflectionProperty;

/**
 * Fallback hydrator for #[Formula] fields.
 *
 * Activates when an entity was NOT hydrated by FormulaObjectHydrator —
 * i.e. loaded via $em->find(), lazy proxy initialization, or any path
 * that bypasses DQL Walker.
 *
 * Executes one additional SQL query per entity (not per field).
 * This is acceptable because these code paths always load one entity at a time.
 *
 * Skips entities already marked as hydrated by FormulaObjectHydrator
 * to avoid duplicate queries.
 */
final class PostLoadListener
{
    /** @var array<class-string, array<string, ReflectionProperty>> */
    private array $reflectionCache = [];

    public function __construct(
        private readonly FormulaRegistry $registry,
    ) {
    }

    public function postLoad(PostLoadEventArgs $args): void
    {
        $entity = $args->getObject();
        $entityClass = $entity::class;

        // Skip if Walker + Hydrator already populated formula fields
        if ($this->registry->isHydrated($entity)) {
            $this->registry->unmarkAsHydrated($entity);
            return;
        }

        $formulas = $this->registry->getForClass($entityClass);

        if ($formulas === []) {
            return;
        }

        $em = $args->getObjectManager();
        $classMetadata = $em->getClassMetadata($entityClass);

        // Build a single SQL query for all formula fields of this entity
        // SELECT (formula1) AS alias1, (formula2) AS alias2, ... FROM table t WHERE t.id = ?
        $tableName = $classMetadata->getTableName();
        $tableAlias = 't0_';

        $selectFragments = [];

        foreach ($formulas as $meta) {
            $resolvedSql = str_replace('{this}', $tableAlias, $meta->sql);
            $selectFragments[] = sprintf('%s AS %s', $resolvedSql, $meta->alias);
        }

        // Build WHERE clause from identifier fields
        $identifierValues = $classMetadata->getIdentifierValues($entity);

        if ($identifierValues === []) {
            return;
        }

        $whereParts = [];
        $params = [];

        foreach ($identifierValues as $fieldName => $value) {
            $columnName = $classMetadata->getColumnName($fieldName);
            $whereParts[] = sprintf('%s.%s = ?', $tableAlias, $columnName);
            $params[] = $value;
        }

        $sql = sprintf(
            'SELECT %s FROM %s %s WHERE %s',
            implode(', ', $selectFragments),
            $tableName,
            $tableAlias,
            implode(' AND ', $whereParts),
        );

        $connection = $em->getConnection();
        $row = $connection->fetchAssociative($sql, $params);

        if ($row === false) {
            return;
        }

        foreach ($formulas as $meta) {
            $rawValue = $row[$meta->alias] ?? null;
            $value = $this->castValue($rawValue, $meta);
            $this->setPropertyValue($entity, $meta, $value);
        }
    }

    private function castValue(mixed $rawValue, FormulaMetadata $meta): mixed
    {
        if ($rawValue === null) {
            return null;
        }

        return match ($meta->phpType) {
            'int', 'integer' => (int)$rawValue,
            'float', 'double' => (float)$rawValue,
            'bool', 'boolean' => (bool)$rawValue,
            default => (string)$rawValue,
        };
    }

    private function setPropertyValue(object $entity, FormulaMetadata $meta, mixed $value): void
    {
        $this->getCachedReflectionProperty($entity::class, $meta->propertyName)
            ->setValue($entity, $value);
    }

    private function getCachedReflectionProperty(string $entityClass, string $propertyName): ReflectionProperty
    {
        if (!isset($this->reflectionCache[$entityClass][$propertyName])) {
            $prop = new ReflectionProperty($entityClass, $propertyName);
            $prop->setAccessible(true);
            $this->reflectionCache[$entityClass][$propertyName] = $prop;
        }

        return $this->reflectionCache[$entityClass][$propertyName];
    }
}
