<?php

namespace Cryonighter\FormulaDoctrine\DBAL;

use Cryonighter\FormulaDoctrine\Metadata\FormulaRegistry;
use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;

/**
 * @internal
 */
final class FormulaDriver extends AbstractDriverMiddleware
{
    public function __construct(
        Driver $driver,
        private readonly FormulaRegistry $registry,
    ) {
        parent::__construct($driver);
    }

    public function connect(array $params): Connection
    {
        return new FormulaConnection(parent::connect($params), $this->registry);
    }
}
