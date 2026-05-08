<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Fixture\Entity\Inherited;

interface FormulaProductInterface extends ProductInterface
{
    public function getOrderCount(): int;

    public function getTotalRevenue(): float;

    public function getMaxItemPrice(): ?float;
}
