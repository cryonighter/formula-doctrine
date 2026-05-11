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
use Doctrine\ORM\Query\Exec\FinalizedSelectExecutor;
use Doctrine\ORM\Query\Exec\PreparedExecutorFinalizer;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\SqlWalker;
use LogicException;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Throwable;

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
 *
 * Supports Walker Chaining: if another OutputWalker was already registered
 * via HINT_CUSTOM_OUTPUT_WALKER, it is invoked first and formula replacement
 * is applied on top of its output. This ensures compatibility with other libraries
 * that also use custom output walkers (e.g. Gedmo, Paginator extensions).
 */
final class FormulaSqlWalker extends SqlWalker implements OutputWalker
{
    /**
     * We cannot inject via constructor (Doctrine instantiates walkers itself),
     * so the registry is passed through a Query hint.
     */
    public const HINT_REGISTRY = 'formula_doctrine.registry';

    /**
     * Hint key used to pass the previously registered OutputWalker class name
     * so we can delegate to it before applying formula replacements.
     */
    public const HINT_PREVIOUS_WALKER = 'formula_doctrine.previous_walker';

    public function getFinalizer(DeleteStatement|SelectStatement|UpdateStatement $ast): SqlFinalizer
    {
        try {
            // Delegate to the previously registered walker if present
            $previousWalker = $this->getPreviousWalkerClass();

            if ($previousWalker) {
                if ($previousWalker instanceof OutputWalker) {
                    $previousFinalizer = $previousWalker->getFinalizer($ast);

                    if ($ast instanceof DeleteStatement || $ast instanceof UpdateStatement) {
                        return $previousFinalizer;
                    }

                    // instanceof cannot be used because the finalizer can inherit it and implement different logic
                    if ($previousFinalizer::class == SingleSelectSqlFinalizer::class) {
                        $sql = (new ReflectionProperty(SingleSelectSqlFinalizer::class, 'sql'))->getValue($previousFinalizer);

                        return new SingleSelectSqlFinalizer($this->applyFormulas($sql, $ast));
                    }

                    // This isn't entirely correct. If the final query contains LIMIT/OFFSET, they will be cached
                    // and pagination won't work. But we won't resolve custom finalizers any other way.
                    $sql = $previousFinalizer->createExecutor($this->getQuery())
                        ->getSqlStatements();

                    return new PreparedExecutorFinalizer(new FinalizedSelectExecutor($this->applyFormulas($sql, $ast)));
                }

                if ($ast instanceof UpdateStatement || $ast instanceof DeleteStatement) {
                    return new PreparedExecutorFinalizer($this->resolveExecutor($ast, $previousWalker));
                }

                // We assume that the author of this walker understands how vulkers in Doctrine ORM
                // are arranged and implemented his logic here, and not in walkSelectStatement()
                $sql = $this->applyFormulas($previousWalker->createSqlForFinalizer($ast), $ast);

                return new PreparedExecutorFinalizer(new FinalizedSelectExecutor($sql));
            }
        } catch (Throwable) {
            // Ignore errors, fall back to our own walker
        }

        if ($ast instanceof UpdateStatement || $ast instanceof DeleteStatement) {
            return new PreparedExecutorFinalizer($this->resolveExecutor($ast, $this));
        }

        assert($ast instanceof SelectStatement);

        return new SingleSelectSqlFinalizer($this->createSqlForFinalizer($ast));
    }

    private function resolveExecutor(DeleteStatement|UpdateStatement $ast, SqlWalker $walker): AbstractSqlExecutor
    {
        return match (true) {
            $ast instanceof UpdateStatement => $walker->createUpdateStatementExecutor($ast),
            $ast instanceof DeleteStatement => $walker->createDeleteStatementExecutor($ast),
        };
    }

    protected function createSqlForFinalizer(SelectStatement $ast): string
    {
        return $this->applyFormulas(parent::createSqlForFinalizer($ast), $ast);
    }

    /**
     * Applies formula replacements to the SQL string for all collected aliases.
     */
    private function applyFormulas(string $sql, SelectStatement $ast): string
    {
        foreach ($this->getFormulasByAlias($ast) as $sqlTableAlias => $formulas) {
            foreach ($formulas as $meta) {
                $resolvedSql = str_replace('{this}', $sqlTableAlias, $meta->sql);

                $sql = str_replace("$sqlTableAlias.$meta->alias", $resolvedSql, $sql);
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

//            if ($metadata->inheritanceType == ClassMetadata::INHERITANCE_TYPE_JOINED) {}
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

    /**
     * Retrieves the previously registered walker class from the query hint.
     * If the hint is not set or is the current walker class, returns null.
     *
     * @throws ReflectionException
     */
    private function getPreviousWalkerClass(): SqlWalker|OutputWalker|null
    {
        $previousWalkerClass = $this->getQuery()->getHint(self::HINT_PREVIOUS_WALKER);

        if (is_string($previousWalkerClass) && $previousWalkerClass !== '' && $previousWalkerClass !== self::class) {
            return $this->createPreviousWalker($previousWalkerClass);
        }

        return null;
    }

    /**
     * Instantiates the previous walker by reusing our own constructor arguments.
     * Reads the target constructor's parameter names via reflection and maps them
     * to the corresponding properties of the current instance (inherited from SqlWalker).
     *
     * @throws ReflectionException
     */
    private function createPreviousWalker(string $walkerClass): SqlWalker|OutputWalker
    {
        $constructor = new ReflectionMethod($walkerClass, '__construct');

        $args = array_map(
            function (ReflectionParameter $param) use ($walkerClass): mixed {
                $reflectionProperty = new ReflectionProperty(SqlWalker::class, $param->getName());

                return $reflectionProperty->getValue($this);
            },
            $constructor->getParameters(),
        );

        return new $walkerClass(...$args);
    }
}
