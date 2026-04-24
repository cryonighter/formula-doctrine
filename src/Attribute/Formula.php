<?php

namespace Cryonighter\FormulaDoctrine\Attribute;

use Attribute;

/**
 * Marks a property as a computed (formula) field.
 *
 * The property will not be persisted. Its value is populated by executing
 * the provided SQL expression during entity hydration.
 *
 * PHP type and nullability are inferred from the property's type hint.
 * Use {this} as a placeholder for the root entity's SQL table alias.
 *
 * Example:
 *   #[Formula('(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)')]
 *   public int $orderCount;
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final readonly class Formula
{
    public function __construct(
        /**
         * Native SQL expression. Use {this} to reference the root entity table alias.
         * Wrap subqueries in parentheses.
         */
        public string $sql,

        /**
         * Column alias in the SELECT clause.
         * Defaults to the property name if not specified.
         */
        public ?string $alias = null,
    ) {}
}
