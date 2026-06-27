<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ratings_inherited_joined')]
class Rating
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: JoinedProduct::class, fetch: 'EAGER')]
        #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
        public JoinedProduct $product,

        // Readonly field formula
        #[Formula('(SELECT (CAST(SUM(rv.rating) AS FLOAT) / COUNT(rv.id)) FROM reviews_inherited_joined rv WHERE rv.product_id = {this}.id)')]
        public readonly float $stars = 0,
    ) {
    }
}
