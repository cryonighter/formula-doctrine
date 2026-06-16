<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\SqlWalker;

/**
 * A minimal output walker used only to extract the SQL table alias
 * assigned to the sentinel DQL alias ('formula_root__') in a wrapper query.
 *
 * Used by FormulaMetadataFactory to correctly resolve the sentinel alias
 * for entities with inheritance (JOINED, SINGLE_TABLE), where the SQL alias
 * of the root entity differs from the subclass table alias.
 *
 * @internal
 */
final class FormulaExtractionWalker extends SqlWalker
{
    public const HINT_ALIAS_MAP = 'formula_doctrine.extraction_alias_map';
    public const SENTINEL_ALIAS = 'formula_root__';

    public function createSqlForFinalizer(SelectStatement $ast): string
    {
        $sql = parent::createSqlForFinalizer($ast);

        $aliasMap = [];

        foreach ($ast->fromClause->identificationVariableDeclarations as $declaration) {
            $rangeDeclaration = $declaration->rangeVariableDeclaration ?? null;

            if ($rangeDeclaration === null) {
                continue;
            }

            $dqlAlias = $rangeDeclaration->aliasIdentificationVariable;

            if ($dqlAlias !== self::SENTINEL_ALIAS) {
                continue;
            }

            $entityClass = $rangeDeclaration->abstractSchemaName;

            $em = $this->getQuery()->getEntityManager();
            $classMetadata = $em->getClassMetadata($entityClass);

            $aliasMap[$entityClass] = $this->getSQLTableAlias($classMetadata->getTableName(), $dqlAlias);

            foreach ($classMetadata->parentClasses as $parentClass) {
                $parentTableName = $em->getClassMetadata($parentClass)->getTableName();
                $aliasMap[$parentClass] = $this->getSQLTableAlias($parentTableName, $dqlAlias);
            }
        }

        $this->getQuery()->setHint(self::HINT_ALIAS_MAP, $aliasMap);

        return $sql;
    }
}
