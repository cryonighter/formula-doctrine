<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Cryonighter\FormulaDoctrine\Query\FormulaExtractionWalker;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;

/**
 * Reads #[Formula] attributes from entity classes via Reflection.
 */
final class FormulaMetadataFactory
{
    /**
     * PHP type hint names → Doctrine DBAL type names.
     * DBAL does not recognise PHP short aliases like 'int', 'bool', 'float'.
     */
    private const PHP_TO_DBAL_TYPE = [
        'int'    => 'integer',
        'float'  => 'float',
        'bool'   => 'boolean',
        'string' => 'string',
        // long aliases — pass through unchanged
        'integer' => 'integer',
        'boolean' => 'boolean',
        'double'  => 'float',
    ];

    /**
     * @param string $className
     * @param string $tableName
     * @param EntityManagerInterface $em
     *
     * @return array<FormulaMetadata>
     */
    public function createForClass(string $className, string $tableName, EntityManagerInterface $em): array
    {
        $reflection = new ReflectionClass($className);
        $result = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Formula::class);

            if (!$attributes) {
                continue;
            }

            /** @var Formula $formula */
            $formula = $attributes[0]->newInstance();
            [$phpType, $dbalType, $nullable] = $this->resolveTypeInfo($property);

            $sqlResolver = fn() => $this->isDqlFormula($formula->sql)
                ? $this->convertDqlToSql($formula->sql, $className, $tableName, $em)
                : $formula->sql;

            $result[] = new FormulaMetadata(
                entityClass: $className,
                propertyName: $property->getName(),
                sqlResolver: $sqlResolver,
                phpType: $phpType,
                dbalType: $dbalType,
                nullable: $nullable,
                alias: $formula->alias ?? $property->getName(),
            );
        }

        return $result;
    }

    /**
     * @return array{string, string, bool} [$phpType, $dbalType, $nullable]
     */
    private function resolveTypeInfo(ReflectionProperty $property): array
    {
        $type = $property->getType();

        if ($type === null) {
            return ['string', 'string', true];
        }

        $nullable = $type->allowsNull();

        if ($type instanceof ReflectionNamedType) {
            $phpType = $type->getName();
            $dbalType = self::PHP_TO_DBAL_TYPE[$phpType] ?? 'string';

            return [$phpType, $dbalType, $nullable];
        }

        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                if ($innerType instanceof ReflectionNamedType && $innerType->getName() !== 'null') {
                    $phpType = $innerType->getName();
                    $dbalType = self::PHP_TO_DBAL_TYPE[$phpType] ?? 'string';

                    return [$phpType, $dbalType, $nullable];
                }
            }
        }

        return ['string', 'string', $nullable];
    }

    private function isDqlFormula(string $query): bool
    {
        return !str_starts_with($query, '(');
    }

    /**
     * Converts a DQL formula to a SQL subquery template.
     * The resulting SQL still contains {this} as a placeholder for the runtime table alias.
     */
    private function convertDqlToSql(string $dql, string $entityClass, string $tableName, EntityManagerInterface $em): string
    {
        $tempDqlAlias = 'formula_root__';

        // Build a fake DQL where the outer alias is defined
        $formulaDql = str_replace('{this}', $tempDqlAlias, $dql);
        $wrapperDql = "SELECT ($formulaDql) FROM $entityClass $tempDqlAlias";

        $wrapperQuery = $em->createQuery($wrapperDql);
        $wrapperQuery->setHint(Query::HINT_CUSTOM_OUTPUT_WALKER, FormulaExtractionWalker::class);
        $wrapperQuery->useQueryCache(false);

        $wrapperSql = $wrapperQuery->getSQL();

        $aliasMap = $wrapperQuery->getHint(FormulaExtractionWalker::HINT_ALIAS_MAP);

        if (empty($aliasMap)) {
            throw new RuntimeException("Could not get SQL alias map for entity $entityClass: $dql");
        }

        // For the subquery we need the alias of the entity class that owns the formula.
        // In JOINED inheritance this is the parent class table alias (e.g. p1_), not the subclass (p2_).
        // Walk up the class hierarchy to find the alias of the table that contains the correlated column.
        $parentClasses = $em->getClassMetadata($entityClass)->parentClasses;
        $parentClass = array_pop($parentClasses) ?? $entityClass;

        $wrapperSqlTableAlias = $aliasMap[$parentClass]
            ?? throw new RuntimeException("Could not resolve SQL alias for entity $entityClass: $dql");

        // Extract the subquery and table alias from the fake SQL query
        $quotedTableName = preg_quote($tableName, '/');
        $pattern = "/SELECT\s+(\((?:[^()]*|(?1))*\))\s+AS\s+\w+\s+FROM\s+$quotedTableName\s+(\w+)/is";

        if (!preg_match($pattern, $wrapperSql, $matches)) {
            throw new RuntimeException("Could not extract subquery SQL from DQL formula for entity $entityClass: $dql");
        }

        [1 => $formulaSql] = $matches;

        // Replace the sentinel SQL alias back with {this} placeholder, so it can be resolved
        // to the real alias at query time — both in FormulaSqlWalker and in FormulaConnection
        return str_replace("$wrapperSqlTableAlias.", '{this}.', $formulaSql);
    }
}
