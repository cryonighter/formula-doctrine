<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reviews')]
class Review
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    public Product $product;

    #[ORM\Column(type: 'smallint')]
    public int $rating;

    #[ORM\Column]
    public string $description;
}
