<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reviews_inherited_joined')]
class Review
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: JoinedProduct::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    public JoinedProduct $product;

    #[ORM\Column(type: 'smallint')]
    public int $rating;

    #[ORM\Column]
    public string $description;
}
