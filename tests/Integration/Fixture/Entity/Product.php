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

    // COUNT подзапрос
    #[Formula('(SELECT COUNT(*) FROM order_items oi WHERE oi.product_id = {this}.id)')]
    public int $orderCount = 0;

    // SUM подзапрос с выражением
    #[Formula('(SELECT COALESCE(SUM(oi.price * oi.quantity), 0) FROM order_items oi WHERE oi.product_id = {this}.id)', alias: 'total')]
    public float $totalRevenue = 0.0;

    // Nullable формула
    #[Formula('(SELECT MAX(oi.price) FROM order_items oi WHERE oi.product_id = {this}.id)')]
    public ?float $maxItemPrice = null;
}
