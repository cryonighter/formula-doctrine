<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;

class EntityWithCustomAlias
{
    #[Formula('(SELECT 1)', alias: 'custom_alias')]
    public int $computed = 0;
}
