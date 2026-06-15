<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products')]
class Product
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\Column]
    public string $name;

    // DQL COUNT subquery
    // Formula and Column together - formula priority
    // #[Formula('(SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = {this}.id)')]
    #[Formula('SELECT COUNT(oi) FROM ' . OrderItem::class . ' oi WHERE oi.product = {this}')]
    #[ORM\Column(name: 'order_count')]
    public int $orderCount = 0;

    // SUM subquery with expression
    #[Formula('(SELECT COALESCE(SUM(oi.price * oi.quantity), 0) FROM order_items oi WHERE oi.product_id = {this}.id)', alias: 'total')]
    public float $totalRevenue = 0.0;

    // Nullable formula
    #[Formula('(SELECT MAX(oi.price) FROM order_items oi WHERE oi.product_id = {this}.id)')]
    public ?float $maxItemPrice = null;
}
