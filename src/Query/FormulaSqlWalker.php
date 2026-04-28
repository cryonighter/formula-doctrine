<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\SqlWalker;

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

    /**
     * Resolved formula metadata for the current query.
     * Populated in getFinalizer(), consumed in walkSelectStatement().
     *
     * @var list<FormulaMetadata>
     */
    private array $activeFormulas = [];

    public function getFinalizer(DeleteStatement|SelectStatement|UpdateStatement $ast): SqlFinalizer
    {
        assert($ast instanceof SelectStatement);

        $query = $this->getQuery();
        $registry = $query->getHint(self::HINT_REGISTRY);

        if ($registry instanceof FormulaRegistry) {
            $this->prepareFormulas($ast, $registry);
        }

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
        if ($this->activeFormulas === []) {
            return $sql;
        }

        $rootAlias = $this->resolveRootDqlAlias($ast);

        if ($rootAlias === null) {
            return $sql;
        }

        $sqlTableAlias = $this->getSQLTableAlias(
            $this->getQueryComponent($rootAlias)['metadata']->getTableName(),
            $rootAlias,
        );

        foreach ($this->activeFormulas as $meta) {
            $resolvedSql = $this->resolvePlaceholder($meta->sql, $sqlTableAlias);
            $sql = str_replace("$sqlTableAlias.$meta->alias", $resolvedSql, $sql);
        }

        return $sql;
    }

    /**
     * Resolves active formulas for the root entity, registers them as scalar
     * results in the RSM, and switches the hydration mode to FormulaObjectHydrator.
     *
     * We access SqlWalker::$rsm directly via Reflection because calling
     * $this->getQuery()->getResultSetMapping() from within getFinalizer()
     * would trigger Parser::parse() recursively and cause infinite recursion.
     */
    private function prepareFormulas(SelectStatement $ast, FormulaRegistry $registry): void
    {
        $rootAlias = $this->resolveRootDqlAlias($ast);

        if ($rootAlias === null) {
            return;
        }

        $entityClass = $this->getEntityClassForAlias($rootAlias);

        if ($entityClass === null || !$registry->hasFormulas($entityClass)) {
            return;
        }

        $this->activeFormulas = $registry->getForClass($entityClass);

        if ($this->activeFormulas === []) {
            return;
        }

        // Formula fields are registered in ClassMetadata as mapped fields by
        // LoadClassMetadataListener. ObjectHydrator will populate them automatically
        // via fieldMappings — no addScalarResult, no custom hydrator needed.
        // The RSM already knows about these fields through ClassMetadata.
    }

    /**
     * Instantiates the previous walker by reusing our own constructor arguments.
     * SqlWalker stores query, parserResult and queryComponents — we pass them to the delegate.
     */
    private function createDelegateWalker(string $walkerClass): ?SqlWalker
    {
        try {
            $queryProperty = new \ReflectionProperty(SqlWalker::class, 'query');
            $parserResultProperty = new \ReflectionProperty(SqlWalker::class, 'parserResult');
            $queryComponentsProperty = new \ReflectionProperty(SqlWalker::class, 'queryComponents');

            return new $walkerClass(
                $queryProperty->getValue($this),
                $parserResultProperty->getValue($this),
                $queryComponentsProperty->getValue($this),
            );
        } catch (\Throwable) {
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
