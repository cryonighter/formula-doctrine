<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;

class EntityWithFormulas
{
    #[Formula('(SELECT COUNT(*) FROM t)')]
    public int $orderCount = 0;

    #[Formula('(SELECT MAX(price) FROM t)')]
    public ?float $maxPrice = null;
}
