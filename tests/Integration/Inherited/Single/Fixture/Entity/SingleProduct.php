<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products_inherited_single')]
#[ORM\InheritanceType('SINGLE_TABLE')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 15)]
#[ORM\DiscriminatorMap([
    'formula_another' => AnotherFormulaSingleProduct::class,
    'formula' => FormulaSingleProduct::class,
    'other' => OtherSingleProduct::class,
])]
abstract class SingleProduct
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\Column]
    public string $name;
}
