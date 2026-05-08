<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Joined\JoinedProduct;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'order_items_inherited')]
class OrderItem
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: JoinedProduct::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    public JoinedProduct $product;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    public string $price;

    #[ORM\Column]
    public int $quantity;
}
