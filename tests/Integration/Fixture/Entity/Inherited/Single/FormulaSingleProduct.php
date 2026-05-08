<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\FormulaProductInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class FormulaSingleProduct extends SingleProduct implements FormulaProductInterface
{
    // COUNT subquery
    // Formula and Column together - formula priority
    #[Formula('(SELECT COUNT(*) FROM order_items_inherited_single oi WHERE oi.product_id = {this}.id)')]
    #[ORM\Column(name: 'order_count')]
    public int $orderCount = 0;

    // SUM subquery with expression
    #[Formula('(SELECT COALESCE(SUM(oi.price * oi.quantity), 0) FROM order_items_inherited_single oi WHERE oi.product_id = {this}.id)', alias: 'total')]
    public float $totalRevenue = 0.0;

    // Nullable formula
    #[Formula('(SELECT MAX(oi.price) FROM order_items_inherited_single oi WHERE oi.product_id = {this}.id)')]
    public ?float $maxItemPrice = null;

    public function getOrderCount(): int
    {
        return $this->orderCount;
    }

    public function getTotalRevenue(): float
    {
        return $this->totalRevenue;
    }

    public function getMaxItemPrice(): ?float
    {
        return $this->maxItemPrice;
    }
}
