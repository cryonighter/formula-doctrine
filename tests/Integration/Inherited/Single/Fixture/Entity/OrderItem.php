<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items_inherited_single')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: SingleProduct::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    public SingleProduct $product;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price;

    #[ORM\Column]
    public int $quantity;
}
