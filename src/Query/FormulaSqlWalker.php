<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Custom Output Walker that injects formula subqueries into the SELECT clause
 * and registers them as scalar results in the ResultSetMapping so that
 * FormulaObjectHydrator can reliably read them from the result row.
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
     * Key used to pass resolved formula metadata (alias → FormulaMetadata)
     * from the Walker to the Hydrator via query hints.
     */
    public const HINT_FORMULA_MAP = 'formula_doctrine.formula_map';

    public function walkSelectStatement(SelectStatement $ast): string
    {
        $sql = parent::walkSelectStatement($ast);

        $registry = $this->getQuery()->getHint(self::HINT_REGISTRY);

        // Registry hint not set — nothing to do, return original SQL
        if (!$registry instanceof FormulaRegistry) {
            return $sql;
        }

        $rootAlias = $this->resolveRootDqlAlias($ast);

        if ($rootAlias === null) {
            return $sql;
        }

        $entityClass = $this->getEntityClassForAlias($rootAlias);

        if ($entityClass === null || !$registry->hasFormulas($entityClass)) {
            return $sql;
        }

        $sqlTableAlias = $this->getSQLTableAlias(
            $this->getQueryComponent($rootAlias)['metadata']->getTableName(),
            $rootAlias,
        );

        $formulaFragments = [];
        $rsm = $this->getQuery()->getResultSetMapping();

        // formulaMap: columnAlias → FormulaMetadata, passed to Hydrator via hint
        $formulaMap = [];

        foreach ($registry->getForClass($entityClass) as $meta) {
            $resolvedSql = $this->resolvePlaceholder($meta->sql, $sqlTableAlias);
            $formulaFragments[] = sprintf('%s AS %s', $resolvedSql, $meta->alias);

            // Register as scalar so Doctrine maps it in the result row
            $rsm->addScalarResult($meta->alias, $meta->alias, $meta->phpType);

            $formulaMap[$meta->alias] = $meta;
        }

        if ($formulaFragments === []) {
            return $sql;
        }

        // Pass the map to the Hydrator
        $this->getQuery()->setHint(self::HINT_FORMULA_MAP, $formulaMap);

        return $this->injectBeforeFrom($sql, implode(', ', $formulaFragments));
    }

    /**
     * Replaces the {this} placeholder with the actual SQL table alias.
     */
    protected function resolvePlaceholder(string $sql, string $tableAlias): string
    {
        return str_replace('{this}', $tableAlias, $sql);
    }

    /**
     * Injects additional SELECT expressions before the FROM clause.
     * Works by finding the first occurrence of " FROM " and inserting before it.
     */
    protected function injectBeforeFrom(string $sql, string $expressions): string
    {
        $fromPos = stripos($sql, ' FROM ');

        if ($fromPos === false) {
            return $sql;
        }

        return substr($sql, 0, $fromPos)
            . ', ' . $expressions
            . substr($sql, $fromPos);
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
