<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products_inherited_joined_formula_another')]
class AnotherFormulaJoinedProduct extends FormulaJoinedProduct
{
}
