<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Joined;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products_inherited_joined_formula_another')]
class AnotherFormulaJoinedProduct extends FormulaJoinedProduct
{
}
