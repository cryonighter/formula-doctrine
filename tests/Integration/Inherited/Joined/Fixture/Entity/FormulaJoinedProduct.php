<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products_inherited_joined_formula')]
class FormulaJoinedProduct extends JoinedProduct
{
    // COUNT subquery
    // Formula and Column together - formula priority
    // #[Formula('(SELECT COUNT(*) FROM order_items_inherited_joined oi WHERE oi.product_id = {this}.id)')]
    #[Formula('SELECT COUNT(oi) FROM ' . OrderItem::class . ' oi WHERE oi.product = {this}')]
    #[ORM\Column(name: 'order_count')]
    public int $orderCount = 0;

    // SUM subquery with expression
    #[Formula('(SELECT COALESCE(SUM(oi.price * oi.quantity), 0) FROM order_items_inherited_joined oi WHERE oi.product_id = {this}.id)', alias: 'total')]
    // #[Formula('SELECT COALESCE(SUM(oi.price * oi.quantity), 0) FROM ' . OrderItem::class . ' oi WHERE oi.product = {this}', alias: 'total')]
    public float $totalRevenue = 0.0;

    // Nullable formula
    #[Formula('(SELECT MAX(oi.price) FROM order_items_inherited_joined oi WHERE oi.product_id = {this}.id)')]
    // #[Formula('SELECT MAX(oi.price) FROM ' . OrderItem::class . ' oi WHERE oi.product = {this}')]
    public ?float $maxItemPrice = null;

    #[Formula('(SELECT MAX(r.created) FROM reviews_inherited_joined r WHERE r.product_id = {this}.id)')]
    public ?DateTimeImmutable $lastReview = null;

    public function __construct(
        #[ORM\ManyToMany(targetEntity: Category::class, mappedBy: 'products')]
        public Collection $categories= new ArrayCollection(),
    ) {
    }
}
