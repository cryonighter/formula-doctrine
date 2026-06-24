<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Inherited\Joined\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'products_inherited_joined_other')]
class OtherJoinedProduct extends JoinedProduct
{
    // Just some field
    #[ORM\Column]
    public string $article;

    // A field whose name matches the field marked with the Formula attribute
    #[ORM\Column]
    public float $totalRevenue = 0.0;

    // A field whose name matches the field marked with the Formula attribute
    #[ORM\Column]
    public ?float $maxItemPrice = null;
}
