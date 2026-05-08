<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Joined;

use Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\ProductInterface;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products_inherited_joined')]
#[ORM\InheritanceType('JOINED')]
#[ORM\DiscriminatorColumn(name: 'type', type: 'string', length: 15)]
#[ORM\DiscriminatorMap([
    'formula_another' => AnotherFormulaJoinedProduct::class,
    'formula' => FormulaJoinedProduct::class,
    'other' => OtherJoinedProduct::class,
])]
abstract class JoinedProduct implements ProductInterface
{
    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    public int $id;

    #[ORM\Column]
    public string $name;

    public function getName(): string
    {
        return $this->name;
    }
}
