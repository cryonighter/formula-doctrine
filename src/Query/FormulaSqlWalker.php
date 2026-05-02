<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Cryonighter\FormulaDoctrine\Query\Exec\FormulaSqlFinalizer;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\FromClause;
use Doctrine\ORM\Query\AST\Join;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\SqlWalker;
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
        assert($ast instanceof SelectStatement);

        $query = $this->getQuery();

        // Delegate to the previously registered walker if present
        $previousWalkerClass = $query->getHint(self::HINT_PREVIOUS_WALKER);

        if (is_string($previousWalkerClass) && $previousWalkerClass !== '' && $previousWalkerClass !== self::class) {
            $previousWalker = $this->createDelegateWalker($previousWalkerClass);

            if ($previousWalker instanceof OutputWalker) {
                $sql = $previousWalker->getFinalizer($ast)
                    ->createExecutor($query)
                    ->getSqlStatements();

                return new FormulaSqlFinalizer($this->applyFormulas($sql, $ast));
            } elseif (method_exists($previousWalker, 'walkSelectStatement')) {
                $sql = $previousWalker->walkSelectStatement($ast);

                return new FormulaSqlFinalizer($this->applyFormulas($sql, $ast));
            }
        }

        return new FormulaSqlFinalizer($this->applyFormulas(parent::walkSelectStatement($ast), $ast));
    }

    public function walkSelectStatement(SelectStatement $ast): string
    {
        return $this->applyFormulas(parent::walkSelectStatement($ast), $ast);
    }

    /**
     * Applies formula replacements to the SQL string for all collected aliases.
     */
    private function applyFormulas(string $sql, SelectStatement $ast): string
    {
        foreach ($this->getFormulasByAlias($ast) as $dqlAlias => $formulas) {
            $entityClass = $this->getEntityClassForAlias($dqlAlias);

            if ($entityClass === null) {
                continue;
            }

            $tableName = $this->getQuery()
                ->getEntityManager()
                ->getClassMetadata($entityClass)
                ->getTableName();

            $sqlTableAlias = $this->getSQLTableAlias($tableName, $dqlAlias);

            foreach ($formulas as $meta) {
                $resolvedSql = $this->resolvePlaceholder($meta->sql, $sqlTableAlias);
                $sql = str_replace("$sqlTableAlias.$meta->alias", $resolvedSql, $sql);
            }
        }

        return $sql;
    }

    /**
     * Returns formula metadata for every DQL alias in the query
     * that maps to an entity class with formula fields.
     *
     * Iterates over:
     *   - root entity aliases (FROM clause)
     *   - join aliases (JOIN clause, including nested joins)
     *
     * Return map of DQL alias → list of FormulaMetadata for all
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

        foreach ($this->collectDqlAliases($ast->fromClause) as $dqlAlias) {
            $entityClass = $this->getEntityClassForAlias($dqlAlias);

            if ($entityClass === null || !$registry->hasFormulas($entityClass)) {
                continue;
            }

            $formulas = $registry->getForClass($entityClass);

            if ($formulas) {
                $formulasByAlias[$dqlAlias] = $formulas;
            }
        }

        return $formulasByAlias;
    }

    /**
     * Collects all DQL aliases from the FROM clause including all join aliases.
     *
     * Example DQL: 'SELECT r, p FROM Review r JOIN r.product p JOIN p.tags t'
     *
     * Returns: ['r', 'p', 't']
     *
     * @return array<string>
     */
    private function collectDqlAliases(FromClause $fromClause): array
    {
        $aliases = [];

        foreach ($fromClause->identificationVariableDeclarations as $declaration) {
            // Root alias
            $rangeDeclaration = $declaration->rangeVariableDeclaration ?? null;

            if ($rangeDeclaration !== null) {
                $aliases[] = $rangeDeclaration->aliasIdentificationVariable;
            }

            // Join aliases (direct joins on this declaration)
            foreach ($declaration->joins as $join) {
                $aliases = array_merge($aliases, $this->collectJoinAliases($join));
            }
        }

        return $aliases;
    }

    /**
     * Recursively collects aliases from a join node.
     *
     * @return array<string>
     */
    private function collectJoinAliases(Join $join): array
    {
        $aliases = [];

        $alias = $join->joinAssociationDeclaration?->aliasIdentificationVariable ?? null;

        if ($alias !== null) {
            $aliases[] = $alias;
        }

        return $aliases;
    }

    /**
     * Instantiates the previous walker by reusing our own constructor arguments.
     * Reads the target constructor's parameter names via reflection and maps them
     * to the corresponding properties of the current instance (inherited from SqlWalker).
     */
    private function createDelegateWalker(string $walkerClass): ?SqlWalker
    {
        try {
            $constructor = new ReflectionMethod($walkerClass, '__construct');

            $args = array_map(
                function (ReflectionParameter $param): mixed {
                    $reflectionProperty = new ReflectionProperty(SqlWalker::class, $param->getName());

                    return $reflectionProperty->getValue($this);
                },
                $constructor->getParameters(),
            );

            return new $walkerClass(...$args);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Replaces the {this} placeholder with the actual SQL table alias.
     */
    private function resolvePlaceholder(string $sql, string $tableAlias): string
    {
        return str_replace('{this}', $tableAlias, $sql);
    }

    /**
     * Resolves the entity FQCN for a given DQL alias via query components.
     */
    private function getEntityClassForAlias(string $dqlAlias): ?string
    {
        /** @var ClassMetadata|null $metadata */
        $metadata = $this->getQueryComponent($dqlAlias)['metadata'] ?? null;

        return $metadata?->getName();
    }
}
