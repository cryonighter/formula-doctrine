<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\ORM\Mapping as ORM;

// No table name
#[ORM\Entity]
class Rating
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: Product::class, fetch: 'EAGER')]
        #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
        public Product $product,

        // Readonly field formula
        #[Formula('(SELECT (CAST(SUM(rv.rating) AS FLOAT) / COUNT(rv.id)) FROM reviews rv WHERE rv.product_id = {this}.id)')]
        public readonly float $stars = 0,
    ) {
    }
}
