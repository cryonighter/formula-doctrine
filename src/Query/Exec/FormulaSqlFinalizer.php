<?php

namespace Cryonighter\FormulaDoctrine\Query\Exec;

use Doctrine\ORM\Query;
use Doctrine\ORM\Query\Exec\AbstractSqlExecutor;
use Doctrine\ORM\Query\Exec\FinalizedSelectExecutor;
use Doctrine\ORM\Query\Exec\SqlFinalizer;

/**
 * The actual finalizer was already applied in previous stages of $sql formation.
 * This is simply a stub to honor the contract.
 */
final readonly class FormulaSqlFinalizer implements SqlFinalizer
{
    public function __construct(
        private string $sql,
    ) {
    }

    public function createExecutor(Query $query): AbstractSqlExecutor
    {
        return new FinalizedSelectExecutor($this->sql);
    }
}
