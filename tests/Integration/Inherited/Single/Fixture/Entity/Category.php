<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'categories_inherited_single')]
class Category
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\Column]
    public string $name;

    // SQL formula — uses Product.totalRevenue alias 'total' via join table
    #[Formula('(SELECT COALESCE(SUM(p.total), 0) FROM products_inherited_single p JOIN category_products_inherited_single cp ON cp.product_id = p.id WHERE cp.category_id = {this}.id)')]
    public float $categoryRevenue = 0.0;

    // DQL formula — uses Product.orderCount formula field via DQL
    #[Formula('SELECT COALESCE(SUM(p.orderCount), 0) FROM ' . FormulaSingleProduct::class . ' p JOIN p.categories cat WHERE cat = {this}')]
    public int $amountOrders = 0;

    public function __construct(
        #[ORM\ManyToMany(targetEntity: FormulaSingleProduct::class, inversedBy: 'categories')]
        #[ORM\JoinTable(
            name: 'category_products_inherited_single',
            joinColumns: [new ORM\JoinColumn(name: 'category_id', referencedColumnName: 'id')],
            inverseJoinColumns: [new ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id')]),
        ]
        public Collection $products  = new ArrayCollection(),
    ) {
    }
}
