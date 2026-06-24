<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Single\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class OtherSingleProduct extends SingleProduct
{
    // Just some field
    #[ORM\Column]
    public string $article;
}
