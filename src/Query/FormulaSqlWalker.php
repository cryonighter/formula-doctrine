<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Doctrine\ORM\Query\AST\ComparisonExpression;
use Doctrine\ORM\Query\AST\ConditionalFactor;
use Doctrine\ORM\Query\AST\ConditionalPrimary;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\FromClause;
use Doctrine\ORM\Query\AST\HavingClause;
use Doctrine\ORM\Query\AST\Join;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\Subselect;
use Doctrine\ORM\Query\AST\SubselectFromClause;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\AST\WhereClause;
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
        [$rootTableName, $rootTableAlias] = $this->getRootTableNameAndAlias($ast->fromClause);

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

                // For example: "p0_.total AS total_1,"
                $columnNameEscaped = preg_quote($columnName);
                $regExp = "/$columnNameEscaped\s+(AS|as)\s+(\w+)([\s,])/";

                preg_match($regExp, $sql, $matches);

                // If the field is not declared in SELECT, it may appear later (in ORDER BY, GROUP BY, HAVING)
                // In this case, we won't have an alias and will have to execute the formula subquery every time
                if (empty($matches)) {
                    $sql = str_replace($columnName, $formulaSql, $sql);

                    continue;
                }

                $columnAlias = $matches[2];

                // TODO: There might be a problem with the recursive query!
                $sqlArr = explode(" FROM $rootTableName $rootTableAlias ", $sql);

                if (count($sqlArr) !== 2) {
                    throw new RuntimeException('Unexpected SQL query structure');
                }

                // Replacing a field declaration with a formula in SELECT (before FROM)
                $sqlArr[0] = preg_replace($regExp, "$formulaSql AS {$columnAlias}{$matches[3]}", $sqlArr[0]);

                // Replace references to the field with a formula in SELECT (before FROM)
                // Unfortunately, it is not possible to use aliases in SELECT, even if they are declared earlier
                // For example: "CASE WHEN p0_.total < 50 THEN 'low' ELSE 'high' END AS sclr_2"
                $sqlArr[0] = str_replace($columnName, $formulaSql, $sqlArr[0]);

                // Replace other references to the formula field with an alias (after FROM)
                $sqlArr[1] = str_replace($columnName, $columnAlias, $sqlArr[1]);

                $sql = implode(" FROM $rootTableName $rootTableAlias ", $sqlArr);
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

        foreach ($this->collectSqlAliases($ast) as ['entityClass' => $entityClass, 'sqlAlias' => $sqlAlias]) {
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
     * Recursively collects all SQL aliases from the AST including all subqueries aliases.
     *
     * Example DQL: 'SELECT r, p FROM Review r JOIN r.product p JOIN p.tags t'
     *
     * Example result: [
     *     ['entityClass' => 'App\Entity\Review',  'sqlAlias' => 'r0_'],
     *     ['entityClass' => 'App\Entity\Product', 'sqlAlias' => 'p1_'],
     *     ['entityClass' => 'App\Entity\Tag',     'sqlAlias' => 't2_'],
     * ]
     *
     * @return array<array<string, string>>
     */
    private function collectSqlAliases(SelectStatement|Subselect $ast): array
    {
        $aliases = $this->collectFromSqlAliases($ast instanceof SelectStatement ? $ast->fromClause : $ast->subselectFromClause);

        if ($ast->whereClause) {
            $aliases = array_merge($aliases, $this->collectWhereSqlAliases($ast->whereClause));
        }

        if ($ast->havingClause) {
            $aliases = array_merge($aliases, $this->collectHavingSqlAliases($ast->havingClause));
        }

        return array_unique($aliases, SORT_REGULAR);
    }

    private function collectHavingSqlAliases(HavingClause $havingClause): array
    {
        $aliases = [];

        $conditionalExpression = $havingClause->conditionalExpression;

        // I don't know if this case exists for HAVING or not
        // I added it in conjunction with WHERE just in case
        if ($conditionalExpression instanceof ConditionalFactor) {
            $conditionalExpression = $conditionalExpression->conditionalPrimary;
        }

        if ($conditionalExpression instanceof ConditionalPrimary) {
            $simpleConditionalExpression = $conditionalExpression->simpleConditionalExpression;

            if ($simpleConditionalExpression instanceof ComparisonExpression) {
                $leftExpression = $simpleConditionalExpression->leftExpression;
                $rightExpression = $simpleConditionalExpression->rightExpression;

                $aliases = array_merge(
                    $aliases,
                    isset($leftExpression->subselect) ? $this->collectSqlAliases($leftExpression->subselect) : [],
                    isset($rightExpression->subselect) ? $this->collectSqlAliases($rightExpression->subselect) : [],
                );
            }
        }

        return $aliases;
    }

    /**
     * Recursively collects all SQL aliases from the WHERE clause.
     *
     * @return array<array<string, string>>
     */
    private function collectWhereSqlAliases(WhereClause $whereClause): array
    {
        $conditionalExpression = $whereClause->conditionalExpression;

        if ($conditionalExpression instanceof ConditionalFactor) {
            $conditionalExpression = $conditionalExpression->conditionalPrimary;
        }

        if ($conditionalExpression instanceof ConditionalPrimary) {
            $simpleConditionalExpression = $conditionalExpression->simpleConditionalExpression;

            // The subselect property contains 4 classes (ExistsExpression, InSubselectExpression, etc)
            // Checking it using instanceof is inconvenient, it's easier to use isset
            if (isset($simpleConditionalExpression?->subselect)) {
                return $this->collectSqlAliases($simpleConditionalExpression?->subselect);
            }

            if ($simpleConditionalExpression instanceof ComparisonExpression) {
                $leftExpression = $simpleConditionalExpression->leftExpression;
                $rightExpression = $simpleConditionalExpression->rightExpression;

                return array_merge(
                    [],
                    isset($leftExpression->subselect) ? $this->collectSqlAliases($leftExpression->subselect) : [],
                    isset($rightExpression->subselect) ? $this->collectSqlAliases($rightExpression->subselect) : [],
                );
            }
        }

        return [];
    }

    /**
     * Collects all SQL aliases from the FROM clause including all JOIN aliases.
     *
     * Example DQL: 'SELECT r, p FROM Review r JOIN r.product p JOIN p.tags t'
     *
     * Example result: [
     *     ['entityClass' => 'App\Entity\Review',  'sqlAlias' => 'r0_'],
     *     ['entityClass' => 'App\Entity\Product', 'sqlAlias' => 'p1_'],
     *     ['entityClass' => 'App\Entity\Tag',     'sqlAlias' => 't2_'],
     * ]
     *
     * @return array<array<string, string>>
     */
    private function collectFromSqlAliases(FromClause|SubselectFromClause $fromClause): array
    {
        $aliases = [];

        foreach ($fromClause->identificationVariableDeclarations as $declaration) {
            // Root alias declaration (FROM clause)
            $rangeDeclaration = $declaration->rangeVariableDeclaration ?? null;

            if ($rangeDeclaration !== null) {
                $dqlAlias = $rangeDeclaration->aliasIdentificationVariable;
                $entityClass = $rangeDeclaration->abstractSchemaName;

                $sqlAlias = $this->resolveSqlAliasByDqlAlias($dqlAlias, $entityClass);

                // We cannot use $sqlAlias as a key, since with single inheritance it will be the same for different entities
                // We cannot use $entityClass as a key, since with subqueries to the same table they will have different aliases
                $aliases[] = ['entityClass' => $entityClass, 'sqlAlias' => $sqlAlias];
            }

            // Join aliases (direct joins on this declaration)
            foreach ($declaration->joins as $join) {
                $aliases = array_merge($aliases, $this->collectJoinSqlAliases($join));
            }
        }

        return $aliases;
    }

    /**
     * Recursively collects aliases from a join node.
     *
     * @return array<array<string, string>>
     */
    private function collectJoinSqlAliases(Join $join): array
    {
        $aliases = [];

        $dqlAlias = $join->joinAssociationDeclaration?->aliasIdentificationVariable ?? null;

        if (!$dqlAlias) {
            return $aliases;
        }

        try {
            $metadata = $this->getMetadataForDqlAlias($dqlAlias);

            $entityClass = $metadata->getName();

            $aliases[] = [
                'entityClass' => $entityClass,
                'sqlAlias' => $this->resolveSqlAliasByDqlAlias($dqlAlias, $entityClass),
            ];

            foreach ($metadata->subClasses as $subClass) {
                $aliases[] = [
                    'entityClass' => $subClass,
                    'sqlAlias' => $this->resolveSqlAliasByDqlAlias($dqlAlias, $subClass),
                ];
            }
        } catch (LogicException) {
            // This means that this functionality will not work and the user will have to refuse it
            // In any case, this is an error of the Doctrine itself or its configuration, we can’t do anything
        }

        return $aliases;
    }

    private function resolveSqlAliasByDqlAlias(string $dqlAlias, string $entityClass): string
    {
        return $this->getSQLTableAlias($this->resolveTableName($entityClass), $dqlAlias);
    }

    private function resolveTableName(string $entityClass): string
    {
        return $this->getQuery()
            ->getEntityManager()
            ->getClassMetadata($entityClass)
            ->getTableName();
    }

    private function getRootTableNameAndAlias(FromClause $fromClause): array
    {
        foreach ($fromClause->identificationVariableDeclarations as $declaration) {
            // Root alias declaration (FROM clause)
            $rangeDeclaration = $declaration->rangeVariableDeclaration ?? null;

            if ($rangeDeclaration !== null) {
                $dqlAlias = $rangeDeclaration->aliasIdentificationVariable;
                $entityClass = $rangeDeclaration->abstractSchemaName;

                $tableName = $this->resolveTableName($entityClass);
                $tableAlias = $this->getSQLTableAlias($tableName, $dqlAlias);

                return [$tableName, $tableAlias];
            }
        }

        throw new RuntimeException('No root table alias found in FROM clause');
    }
}
