<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\FromClause;
use Doctrine\ORM\Query\AST\Join;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\AbstractSqlExecutor;
use Doctrine\ORM\Query\Exec\PreparedExecutorFinalizer;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\SqlWalker;
use LogicException;
use RuntimeException;

/**
 * Custom Output Walker that injects formula subqueries into the SELECT clause.
 *
 *  Handles both root entities and eagerly joined entities:
 *    SELECT r, p FROM Review r JOIN r.product p
 *    → formula fields on Product (p) are replaced with subqueries using p's SQL alias
 *
 * Formula fields are registered in ClassMetadata by LoadClassMetadataListener,
 * so ObjectHydrator can hydrate them directly via standard fieldMappings —
 * no custom hydrator or setHydrationMode() needed.
 */
class FormulaSqlWalker extends SqlWalker implements OutputWalker
{
    /**
     * We cannot inject via constructor (Doctrine instantiates walkers itself),
     * so the registry is passed through a Query hint.
     */
    final public const HINT_REGISTRY = 'formula_doctrine.registry';

    public function getFinalizer(DeleteStatement|SelectStatement|UpdateStatement $ast): SqlFinalizer
    {
        if ($ast instanceof UpdateStatement || $ast instanceof DeleteStatement) {
            return new PreparedExecutorFinalizer($this->resolveExecutor($ast, $this));
        }

        assert($ast instanceof SelectStatement);

        return new SingleSelectSqlFinalizer($this->createSqlForFinalizer($ast));
    }

    final protected function resolveExecutor(DeleteStatement|UpdateStatement $ast, SqlWalker $walker): AbstractSqlExecutor
    {
        return match (true) {
            $ast instanceof UpdateStatement => $walker->createUpdateStatementExecutor($ast),
            $ast instanceof DeleteStatement => $walker->createDeleteStatementExecutor($ast),
        };
    }

    final protected function createSqlForFinalizer(SelectStatement $ast): string
    {
        return $this->applyFormulas(parent::createSqlForFinalizer($ast), $ast);
    }

    /**
     * Applies formula replacements to the SQL string for all collected aliases.
     */
    final protected function applyFormulas(string $sql, SelectStatement $ast): string
    {
        foreach ($this->getFormulasByAlias($ast) as $sqlTableAlias => $formulas) {
            foreach ($formulas as $meta) {
                $columnName = "$sqlTableAlias.$meta->alias";

                $countColumnName = substr_count($sql, $columnName);

                if (!$countColumnName) {
                    continue;
                }

                $formulaSql = str_replace('{this}', $sqlTableAlias, $meta->sql);

                if ($countColumnName === 1) {
                    $sql = str_replace($columnName, $formulaSql, $sql);

                    continue;
                }

                $columnNameEscaped = preg_quote($columnName);
                $regExp = "/$columnNameEscaped\s+(AS|as)\s+(\w+)([\s,])/";
                preg_match($regExp, $sql, $matches);

                if (empty($matches)) {
                    $sql = str_replace($columnName, $formulaSql, $sql);

                    continue;
                }

                $columnAlias = $matches[2];

                $sql = preg_replace($regExp, "$formulaSql AS {$columnAlias}{$matches[3]}", $sql, 1);
                $sql = str_replace($columnName, $columnAlias, $sql);
            }
        }

        return $sql;
    }

    /**
     * Returns formula metadata for every SQL alias in the query
     * that maps to an entity class with formula fields.
     *
     * Iterates over:
     *   - root entity aliases (FROM clause)
     *   - join aliases (JOIN clause, including nested joins)
     *
     * Return map of SQL alias → list of FormulaMetadata for all
     * entities in the query that have formula fields.
     *
     * @return array<string, array<FormulaMetadata>>
     */
    private function getFormulasByAlias(SelectStatement $ast): array
    {
        $registry = $this->getQuery()->getHint(self::HINT_REGISTRY);

        if (!$registry instanceof FormulaMetadataRegistry) {
            throw new RuntimeException('Formula registry not set in query hint');
        }

        $formulasByAlias = [];

        foreach ($this->collectSqlAliasMap($ast->fromClause) as $entityClass => $sqlAlias) {
            if (!$registry->hasFormulas($entityClass)) {
                continue;
            }

            $formulas = $registry->getForClass($entityClass);

            if ($formulas) {
                $formulasByAlias[$sqlAlias] = array_merge($formulasByAlias[$sqlAlias] ?? [], $formulas);
            }
        }

        return $formulasByAlias;
    }

    /**
     * Collects all SQL aliases from the FROM clause including all JOIN aliases.
     *
     * Example DQL: 'SELECT r, p FROM Review r JOIN r.product p JOIN p.tags t'
     *
     * Example result: ['App\Entity\Review' => 'r0_', 'App\Entity\Product' => 'p1_', 'App\Entity\Tag' => 't2_']
     *
     * @return array<string, string>
     */
    private function collectSqlAliasMap(FromClause $fromClause): array
    {
        $aliases = [];

        foreach ($fromClause->identificationVariableDeclarations as $declaration) {
            // Root alias declaration (FROM clause)
            $rangeDeclaration = $declaration->rangeVariableDeclaration ?? null;

            if ($rangeDeclaration !== null) {
                $dqlAlias = $rangeDeclaration->aliasIdentificationVariable;
                $entityClass = $rangeDeclaration->abstractSchemaName;

                $aliases[$entityClass] = $this->resolveSqlAliasByDqlAlias($dqlAlias, $entityClass);
            }

            // Join aliases (direct joins on this declaration)
            foreach ($declaration->joins as $join) {
                $aliases = array_merge($aliases, $this->collectJoinSqlAliasMap($join));
            }
        }

        return $aliases;
    }

    /**
     * Recursively collects aliases from a join node.
     *
     * @return array<string, string>
     */
    private function collectJoinSqlAliasMap(Join $join): array
    {
        $aliases = [];

        $dqlAlias = $join->joinAssociationDeclaration?->aliasIdentificationVariable ?? null;

        if (!$dqlAlias) {
            return $aliases;
        }

        try {
            $metadata = $this->getMetadataForDqlAlias($dqlAlias);

            $entityClass = $metadata->getName();

            $aliases[$entityClass] = $this->resolveSqlAliasByDqlAlias($dqlAlias, $entityClass);

            foreach ($metadata->subClasses as $subClass) {
                $aliases[$subClass] = $this->resolveSqlAliasByDqlAlias($dqlAlias, $subClass);
            }
        } catch (LogicException) {
            // This means that this functionality will not work and the user will have to refuse it
            // In any case, this is an error of the Doctrine itself or its configuration, we can’t do anything
        }

        return $aliases;
    }

    private function resolveSqlAliasByDqlAlias(string $dqlAlias, string $entityClass): string
    {
        $tableName = $this->getQuery()
            ->getEntityManager()
            ->getClassMetadata($entityClass)
            ->getTableName();

        return $this->getSQLTableAlias($tableName, $dqlAlias);
    }
}
