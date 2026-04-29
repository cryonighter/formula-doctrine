<?php

namespace Cryonighter\FormulaDoctrine\Tests\Integration;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\Result;
use Doctrine\DBAL\Driver\Statement;

/**
 * DBAL 4 middleware-based query logger for tests.
 * Counts and records executed SQL statements.
 *
 * Usage:
 *   $logger = new QueryLogger();
 *   $dbalConfig->setMiddlewares([$logger]);
 *   // ... run queries ...
 *   $logger->getQueryCount();
 */
final class QueryLogger implements Middleware
{
    /** @var array<string> */
    private array $queries = [];

    public function wrap(Driver $driver): Driver
    {
        $queries = &$this->queries;

        return new class ($driver, $queries) extends AbstractDriverMiddleware
        {
            /** @param array<string> $queries */
            public function __construct(
                Driver $driver,
                private array &$queries,
            ) {
                parent::__construct($driver);
            }

            public function connect(array $params): Connection
            {
                $queries = &$this->queries;

                return new class (parent::connect($params), $queries) extends AbstractConnectionMiddleware
                {
                    /** @param array<string> $queries */
                    public function __construct(
                        Connection $connection,
                        private array &$queries,
                    ) {
                        parent::__construct($connection);
                    }

                    public function prepare(string $sql): Statement
                    {
                        $this->queries[] = $sql;

                        return parent::prepare($sql);
                    }

                    public function query(string $sql): Result
                    {
                        $this->queries[] = $sql;

                        return parent::query($sql);
                    }

                    public function exec(string $sql): int
                    {
                        $this->queries[] = $sql;

                        return parent::exec($sql);
                    }
                };
            }
        };
    }

    public function getQueryCount(): int
    {
        return count($this->queries);
    }

    /** @return array<string> */
    public function getQueries(): array
    {
        return $this->queries;
    }

    public function reset(): void
    {
        $this->queries = [];
    }
}
