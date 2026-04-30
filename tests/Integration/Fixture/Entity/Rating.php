<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ratings')]
class Rating
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Product::class, fetch: 'EAGER')]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    public Product $product;

    #[Formula('(SELECT (SUM(rv.rating) / COUNT(rv.id)) FROM reviews rv WHERE rv.product_id = {this}.id)')]
    public float $stars = 0;
}
