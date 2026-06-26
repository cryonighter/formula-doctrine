<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity;

use DateTimeImmutable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'reviews_inherited_single')]
class Review
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\ManyToOne(targetEntity: SingleProduct::class)]
    #[ORM\JoinColumn(name: 'product_id', referencedColumnName: 'id', nullable: false)]
    public SingleProduct $product;

    #[ORM\Column(type: 'smallint')]
    public int $rating;

    #[ORM\Column]
    public string $description;

    #[ORM\Column(type: 'datetime_immutable')]
    public DateTimeImmutable $created;
}
