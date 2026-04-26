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
use ReflectionProperty;

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

        $formulaFragments = [];
        $formulaMap = [];

        foreach ($this->activeFormulas as $meta) {
            $resolvedSql = $this->resolvePlaceholder($meta->sql, $sqlTableAlias);
            $formulaFragments[] = sprintf('%s AS %s', $resolvedSql, $meta->alias);
            $formulaMap[$meta->alias] = $meta;
        }

        $this->getQuery()->setHint(self::HINT_FORMULA_MAP, $formulaMap);

        return $this->injectBeforeFrom($sql, implode(', ', $formulaFragments));
    }

    /**
     * Resolves active formulas for the root entity and registers them
     * as scalar results directly into SqlWalker's own $rsm property,
     * which is the RSM being built by Parser at this moment.
     *
     * We must NOT call $this->getQuery()->getResultSetMapping() here —
     * that method calls parse() internally, which causes infinite recursion
     * because getFinalizer() is itself called from within Parser::parse().
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

        // SqlWalker holds the RSM being constructed by Parser in a protected property $rsm.
        // We access it directly via Reflection — this is safe because getFinalizer()
        // is called from Parser::parse() at exactly the moment when $rsm is being built.
        $rsmProperty = new ReflectionProperty(SqlWalker::class, 'rsm');

        /** @var \Doctrine\ORM\Query\ResultSetMapping $rsm */
        $rsm = $rsmProperty->getValue($this);

        foreach ($this->activeFormulas as $meta) {
            $rsm->addScalarResult($meta->alias, $meta->alias, $meta->dbalType);
        }
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
