<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories')]
class Category
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\Column]
    public string $name;

    // SQL formula — uses Product.totalRevenue alias 'total' via join table
    #[Formula('(SELECT COALESCE(SUM(p.total), 0) FROM products p JOIN category_products cp ON cp.product_id = p.id WHERE cp.category_id = {this}.id)')]
    public float $categoryRevenue = 0.0;

    // DQL formula — uses Product.orderCount formula field via DQL
    #[Formula('SELECT COALESCE(SUM(p.orderCount), 0) FROM ' . Product::class . ' p JOIN p.categories cat WHERE cat = {this}')]
    public int $amountOrders = 0;

    public function __construct(
        #[ORM\ManyToMany(targetEntity: Product::class, inversedBy: 'categories')]
        #[ORM\JoinTable(name: 'category_products')]
        public Collection $products  = new ArrayCollection(),
    ) {
    }
}
