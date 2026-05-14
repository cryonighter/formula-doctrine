<?php

namespace Cryonighter\FormulaDoctrine\Query;

use Doctrine\ORM\Query\AST\DeleteStatement;
use Doctrine\ORM\Query\AST\SelectStatement;
use Doctrine\ORM\Query\AST\UpdateStatement;
use Doctrine\ORM\Query\Exec\FinalizedSelectExecutor;
use Doctrine\ORM\Query\Exec\PreparedExecutorFinalizer;
use Doctrine\ORM\Query\Exec\SingleSelectSqlFinalizer;
use Doctrine\ORM\Query\Exec\SqlFinalizer;
use Doctrine\ORM\Query\OutputWalker;
use Doctrine\ORM\Query\SqlWalker;
use ReflectionException;
use ReflectionMethod;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Throwable;

/**
 * Supports Walker Chaining: if another OutputWalker was already registered
 * via HINT_CUSTOM_OUTPUT_WALKER, it is invoked first and formula replacement
 * is applied on top of its output. This ensures compatibility with other libraries
 * that also use custom output walkers (e.g. Gedmo, Paginator extensions).
 */
final class ChainingFormulaSqlWalker extends FormulaSqlWalker
{
    /**
     * Hint key used to pass the previously registered OutputWalker class name
     * so we can delegate to it before applying formula replacements.
     */
    public const HINT_PREVIOUS_WALKER = 'formula_doctrine.previous_walker';

    public function getFinalizer(DeleteStatement|SelectStatement|UpdateStatement $ast): SqlFinalizer
    {
        try {
            // Delegate to the previously registered walker if present
            $previousWalker = $this->getPreviousWalkerClass();

            if (!$previousWalker) {
                throw new RuntimeException('Previous walker not set in query hint');
            }

            if ($previousWalker instanceof OutputWalker) {
                $previousFinalizer = $previousWalker->getFinalizer($ast);

                if ($ast instanceof DeleteStatement || $ast instanceof UpdateStatement) {
                    return $previousFinalizer;
                }

                // instanceof cannot be used because the finalizer can inherit it and implement different logic
                if ($previousFinalizer::class == SingleSelectSqlFinalizer::class) {
                    $sql = (new ReflectionProperty(SingleSelectSqlFinalizer::class, 'sql'))->getValue($previousFinalizer);

                    return new SingleSelectSqlFinalizer($this->applyFormulas($sql, $ast));
                }

                // This isn't entirely correct. If the final query contains LIMIT/OFFSET, they will be cached
                // and pagination won't work. But we won't resolve custom finalizers any other way.
                $sql = $previousFinalizer->createExecutor($this->getQuery())
                    ->getSqlStatements();

                return new PreparedExecutorFinalizer(new FinalizedSelectExecutor($this->applyFormulas($sql, $ast)));
            }

            if ($ast instanceof UpdateStatement || $ast instanceof DeleteStatement) {
                return new PreparedExecutorFinalizer($this->resolveExecutor($ast, $previousWalker));
            }

            // We assume that the author of this walker understands how vulkers in Doctrine ORM
            // are arranged and implemented his logic here, and not in walkSelectStatement()
            $sql = $this->applyFormulas($previousWalker->createSqlForFinalizer($ast), $ast);

            return new PreparedExecutorFinalizer(new FinalizedSelectExecutor($sql));
        } catch (Throwable) {
            return parent::getFinalizer($ast);
        }
    }

    /**
     * Retrieves the previously registered walker class from the query hint.
     * If the hint is not set or is the current walker class, returns null.
     *
     * @throws ReflectionException
     */
    private function getPreviousWalkerClass(): SqlWalker|OutputWalker|null
    {
        $previousWalkerClass = $this->getQuery()->getHint(self::HINT_PREVIOUS_WALKER);

        if (is_string($previousWalkerClass) && $previousWalkerClass !== '' && $previousWalkerClass !== self::class) {
            return $this->createPreviousWalker($previousWalkerClass);
        }

        return null;
    }

    /**
     * Instantiates the previous walker by reusing our own constructor arguments.
     * Reads the target constructor's parameter names via reflection and maps them
     * to the corresponding properties of the current instance (inherited from SqlWalker).
     *
     * @throws ReflectionException
     */
    private function createPreviousWalker(string $walkerClass): SqlWalker|OutputWalker
    {
        $constructor = new ReflectionMethod($walkerClass, '__construct');

        $args = array_map(
            function (ReflectionParameter $param) use ($walkerClass): mixed {
                $reflectionProperty = new ReflectionProperty(SqlWalker::class, $param->getName());

                return $reflectionProperty->getValue($this);
            },
            $constructor->getParameters(),
        );

        return new $walkerClass(...$args);
    }
}
