<?php

namespace Cryonighter\FormulaDoctrine\Metadata;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Cryonighter\FormulaDoctrine\Mapping\FormulaMetadata;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;

/**
 * Reads #[Formula] attributes from entity classes via Reflection.
 */
final class FormulaMetadataFactory
{
    /**
     * @param class-string $className
     * @return list<FormulaMetadata>
     */
    public function createForClass(string $className): array
    {
        $reflection = new ReflectionClass($className);
        $result = [];

        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Formula::class);

            if ($attributes === []) {
                continue;
            }

            /** @var Formula $formula */
            $formula = $attributes[0]->newInstance();
            [$phpType, $nullable] = $this->resolveTypeInfo($property);

            $result[] = new FormulaMetadata(
                entityClass: $className,
                propertyName: $property->getName(),
                sql: $formula->sql,
                phpType: $phpType,
                nullable: $nullable,
                alias: $formula->alias ?? $property->getName(),
            );
        }

        return $result;
    }

    /**
     * Resolves PHP type name and nullability from a property's type hint.
     *
     * @return array{string, bool} [$phpType, $nullable]
     */
    private function resolveTypeInfo(ReflectionProperty $property): array
    {
        $type = $property->getType();

        if ($type === null) {
            return ['string', true];
        }

        $nullable = $type->allowsNull();

        // ?int, int, string, float, bool — the common case
        if ($type instanceof ReflectionNamedType) {
            return [$type->getName(), $nullable];
        }

        // int|string|null — take the first non-null type as a best-effort fallback
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $innerType) {
                if ($innerType instanceof ReflectionNamedType && $innerType->getName() !== 'null') {
                    return [$innerType->getName(), $nullable];
                }
            }
        }

        return ['string', $nullable];
    }
}
