<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;
use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
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
     * Resolved formula metadata for the current query.
     * Populated in getFinalizer(), consumed in walkSelectStatement().
     *
     * @var list<FormulaMetadata>
     */
    private array $activeFormulas = [];

    public function getFinalizer(DeleteStatement|SelectStatement|UpdateStatement $ast): SqlFinalizer
    {
        assert($ast instanceof SelectStatement);

        $registry = $this->getQuery()->getHint(self::HINT_REGISTRY);

        if ($registry instanceof FormulaRegistry) {
            $this->prepareFormulas($ast, $registry);
        }

        return new SingleSelectSqlFinalizer($this->walkSelectStatement($ast));
    }

    public function walkSelectStatement(SelectStatement $ast): string
    {
        $sql = parent::walkSelectStatement($ast);

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
     * Replaces the {this} placeholder with the actual SQL table alias.
     */
    protected function resolvePlaceholder(string $sql, string $tableAlias): string
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
     *
     * @return class-string|null
     */
    private function getEntityClassForAlias(string $dqlAlias): ?string
    {
        $component = $this->getQueryComponent($dqlAlias);

        /** @var \Doctrine\ORM\Mapping\ClassMetadata|null $metadata */
        $metadata = $component['metadata'] ?? null;

        return $metadata?->getName();
    }
}
