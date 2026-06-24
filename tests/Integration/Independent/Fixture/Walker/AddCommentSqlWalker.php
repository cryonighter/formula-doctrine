<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration\Independent\Fixture\Walker;

use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\SqlWalker;

/**
 * Minimal test OutputWalker that prepends a SQL comment to the generated query.
 * Used to verify Walker Chaining: FormulaSqlWalker must invoke this walker first,
 * then apply formula replacements on top of its output.
 */
final class AddCommentSqlWalker extends SqlWalker implements OutputWalker
{
    public const COMMENT = '/* third-party-walker */';

    public function getFinalizer(DeleteStatement|SelectStatement|UpdateStatement $ast): SqlFinalizer
    {
        assert($ast instanceof SelectStatement);

        $sql = $this->walkSelectStatement($ast);

        return new SingleSelectSqlFinalizer(self::COMMENT . ' ' . $sql);
    }
}
