<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;

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
     * @param class-string $className
     * @return list<FormulaMetadata>
     */
    public function createForClass(string $className): array
    {
        $reflection = new \ReflectionClass($className);
        $result = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Formula::class);

            if ($attributes === []) {
                continue;
            }

            /** @var Formula $formula */
            $formula = $attributes[0]->newInstance();
            [$phpType, $dbalType, $nullable] = $this->resolveTypeInfo($property);

            $result[] = new FormulaMetadata(
                entityClass: $className,
                propertyName: $property->getName(),
                sql: $formula->sql,
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
    private function resolveTypeInfo(\ReflectionProperty $property): array
    {
        $type = $property->getType();

        if ($type === null) {
            return ['string', 'string', true];
        }

        $nullable = $type->allowsNull();

        if ($type instanceof \ReflectionNamedType) {
            $phpType = $type->getName();
            $dbalType = self::PHP_TO_DBAL_TYPE[$phpType] ?? 'string';

            return [$phpType, $dbalType, $nullable];
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                if ($innerType instanceof \ReflectionNamedType && $innerType->getName() !== 'null') {
                    $phpType = $innerType->getName();
                    $dbalType = self::PHP_TO_DBAL_TYPE[$phpType] ?? 'string';

                    return [$phpType, $dbalType, $nullable];
                }
            }
        }

        return ['string', 'string', $nullable];
    }
}
