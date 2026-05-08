<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Joined\JoinedProduct;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reviews_inherited')]
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
