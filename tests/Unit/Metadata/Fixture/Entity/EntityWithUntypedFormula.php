<?php

namespace Cryonighter\FormulaDoctrine\Tests\Unit\Metadata\Fixture\Entity;

use Cryonighter\FormulaDoctrine\Attribute\Formula;

class EntityWithUntypedFormula
{
    #[Formula('(SELECT "hello")')]
    public $untypedField;
}
