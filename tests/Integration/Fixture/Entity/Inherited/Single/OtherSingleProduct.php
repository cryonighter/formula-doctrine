<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited\Single;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class OtherSingleProduct extends SingleProduct
{
    // Just some field
    #[ORM\Column]
    public string $article;
}
