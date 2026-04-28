<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\DeleteStatement;
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
 * Formula fields are registered in ClassMetadata by LoadClassMetadataListener,
 * so ObjectHydrator can hydrate them directly via standard fieldMappings —
 * no custom hydrator or setHydrationMode() needed.
 *
 * Supports Walker Chaining: if another OutputWalker was already registered
 * via HINT_CUSTOM_OUTPUT_WALKER, it is invoked first and our formula replacement
 * is applied on top of its output. This ensures compatibility with other libraries
 * that also use custom output walkers (e.g. Gedmo, Paginator extensions).
 */
final class FormulaSqlWalker extends SqlWalker implements OutputWalker
{
    /**
     * We cannot inject via constructor (Doctrine instantiates walkers itself),
     * so the registry is passed through a Query hint.
     *
     * Hint key: FormulaSqlWalker::HINT_REGISTRY
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
                $finalizer = $previousWalker->getFinalizer($ast);
                $sql = $finalizer->createExecutor($query)->getSqlStatements();

                return new SingleSelectSqlFinalizer($this->applyFormulas($sql, $ast));
            }
        }

        return new SingleSelectSqlFinalizer($this->walkSelectStatement($ast));
    }

    public function walkSelectStatement(SelectStatement $ast): string
    {
        return $this->applyFormulas(parent::walkSelectStatement($ast), $ast);
    }

    /**
     * Applies formula replacements to the given SQL string.
     */
    private function applyFormulas(string $sql, SelectStatement $ast): string
    {
        $registry = $this->getQuery()->getHint(self::HINT_REGISTRY);

        if (!$registry instanceof FormulaRegistry) {
            throw new RuntimeException('Formula registry not set in query hint.');
        }

        $rootAlias = $this->resolveRootDqlAlias($ast);

        if ($rootAlias === null) {
            return $sql;
        }

        $entityClass = $this->getEntityClassForAlias($rootAlias);

        if (!$entityClass || !$registry->hasFormulas($entityClass)) {
            return $sql;
        }

        $activeFormulas = $registry->getForClass($entityClass);

        $sqlTableAlias = $this->getSQLTableAlias(
            $this->getQueryComponent($rootAlias)['metadata']->getTableName(),
            $rootAlias,
        );

        foreach ($activeFormulas as $meta) {
            $resolvedSql = $this->resolvePlaceholder($meta->sql, $sqlTableAlias);
            $sql = str_replace("$sqlTableAlias.$meta->alias", $resolvedSql, $sql);
        }

        return $sql;
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
                function (ReflectionParameter $param) {
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
     * Returns the DQL alias of the root entity in the FROM clause.
     */
    private function resolveRootDqlAlias(SelectStatement $ast): ?string
    {
        $fromClause = $ast->fromClause;

        if ($fromClause === null) {
            return null;
        }

        foreach ($fromClause->identificationVariableDeclarations as $declaration) {
            $rangeDecl = $declaration->rangeVariableDeclaration ?? null;

            if ($rangeDecl !== null) {
                return $rangeDecl->aliasIdentificationVariable;
            }
        }

        return null;
    }

    /**
     * Resolves the entity FQCN for a given DQL alias via query components.
     */
    private function getEntityClassForAlias(string $dqlAlias): ?string
    {
        $component = $this->getQueryComponent($dqlAlias);

        /** @var ClassMetadata|null $metadata */
        $metadata = $component['metadata'] ?? null;

        return $metadata?->getName();
    }
}
