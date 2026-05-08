<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ratings_inherited_single')]
class Rating
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SingleProduct::class, fetch: 'EAGER')]
        #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
        public SingleProduct $product,

        // Readonly field formula
        #[Formula('(SELECT (SUM(rv.rating) / COUNT(rv.id)) FROM reviews_inherited_single rv WHERE rv.product_id = {this}.id)')]
        public readonly float $stars = 0,
    ) {
    }
}
