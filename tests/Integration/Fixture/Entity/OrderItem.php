<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    public Product $product;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price;

    #[ORM\Column]
    public int $quantity;
}
