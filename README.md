# Formula Doctrine

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE)
[![Total Downloads][ico-downloads]][link-downloads]

Hibernate-style `#[Formula]` computed fields for Doctrine ORM 3 entities.

Adds support for read-only, SQL-computed entity properties populated via
subqueries, aggregations and joins — without N+1 queries.

Example with native SQL subquery – **must be** enclosed in parentheses:

```php
#[ORM\Entity]
class Customer
{
    #[Formula('(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)')]
    public int $orderCount = 0;
}
```

Example using DQL subquery – **should not** be enclosed in parentheses:

```php
#[ORM\Entity]
class Customer
{
    #[Formula('SELECT COUNT(o) FROM App\Entity\Order o WHERE o.customer = {this}')]
    public int $orderCount = 0;
}
```

## Requirements

- **PHP >= 8.2.0** but the latest stable version of PHP is recommended

## Install

### Symfony

If you are using Symfony, install the bundle instead — it wires everything automatically via Symfony DI:

```shell script
composer require cryonighter/formula-doctrine-bundle
```

See [cryonighter/formula-doctrine-bundle](https://github.com/cryonighter/formula-doctrine-bundle)
for installation and configuration instructions.

### Standalone

If you use another framework or write in bare PHP:

```shell script
composer require cryonighter/formula-doctrine
```

Bootstrap the stack manually when creating your `EntityManager`:

```
<?php

use Cryonighter\FormulaDoctrine\DBAL\FormulaMiddleware;
use Cryonighter\FormulaDoctrine\Configuration\FormulaDoctrineConfigurator;
use Cryonighter\FormulaDoctrine\EventListener\LoadClassMetadataListener;
use Cryonighter\FormulaDoctrine\EventListener\PostGenerateSchemaListener;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataFactory;
use Cryonighter\FormulaDoctrine\Metadata\FormulaMetadataRegistry;
use Doctrine\DBAL\Configuration as DbalConfiguration;
use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;

// 1. Build the registry
$registry = new FormulaMetadataRegistry(new FormulaMetadataFactory());

// 2. Configure DBAL — add FormulaMiddleware
$dbalConfig = new DbalConfiguration();
$dbalConfig->setMiddlewares([
    new FormulaMiddleware($registry),
    // ... your other middlewares
]);

$connection = DriverManager::getConnection([
    'driver' => 'pdo_pgsql',
    'url'    => 'postgresql://user:pass@localhost/mydb',
], $dbalConfig);

// 3. Configure ORM
$ormConfig = ORMSetup::createAttributeMetadataConfiguration(
    paths: [__DIR__ . '/src/Entity'],
    isDevMode: true,
);

$configurator = new FormulaDoctrineConfigurator($registry);
$configurator->configure($ormConfig);

// 4. Create EntityManager
$em = new EntityManager($connection, $ormConfig);

// 5. Register event listeners
$eventManager = $em->getEventManager();

$eventManager->addEventListener(
    Events::loadClassMetadata,
    new LoadClassMetadataListener($registry),
);

$eventManager->addEventListener(
    'postGenerateSchema',
    new PostGenerateSchemaListener($registry),
);
```

That's it. Formula fields on your entities will be populated automatically
on every query — DQL, `find()`, `findBy()`, eager associations and lazy proxies.


## Usage

### Basic example

Add `#[Formula]` to any property on a Doctrine entity.
The property **must not** be mapped with `#[ORM\Column]`.

```php
use Cryonighter\FormulaDoctrine\Attribute\Formula;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'customers')]
class Customer
{
    #[ORM\Id, ORM\Column, ORM\GeneratedValue]
    public int $id;

    #[ORM\Column]
    public string $name;

    // DQL — must NOT be enclosed in parentheses
    #[Formula('SELECT COUNT(o) FROM App\Entity\Order o WHERE o.customer = {this}')]
    public int $orderCount = 0;

    // Native SQL — must be enclosed in parentheses
    #[Formula('(SELECT COALESCE(SUM(oi.price), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.customer_id = {this}.id)')]
    public float $totalRevenue = 0.0;

    // Nullable formula
    #[Formula('(SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = {this}.id)')]
    public ?string $lastOrderDate = null;
}
```


### SQL vs DQL expressions

`#[Formula]` accepts both **native SQL** and **DQL** expressions. The rule is simple:

- **Native SQL** — enclose the expression in parentheses: `#[Formula('(SELECT ...)')]`
- **DQL** — no parentheses: `#[Formula('SELECT ...')]`

In DQL, use entity class names and mapped field names instead of table and column names.


### Fetching entities

No changes to your query code are needed.
Formula fields are populated automatically on every DQL `SELECT`:

```php
$customers = $entityManager
    ->createQuery('SELECT c FROM App\Entity\Customer c')
    ->getResult();

foreach ($customers as $customer) {
    echo $customer->orderCount;    // populated from subquery
    echo $customer->totalRevenue;  // populated from subquery
}
```


A single SQL query is executed — no N+1:

```postgresql
SELECT c0_.id AS id_0,
       c0_.name AS name_1,
       (SELECT COUNT(o0_.id) AS sclr_1 FROM orders o0_ WHERE o0_.customer_id = c0_.id) AS orderCount_2,
       (SELECT COALESCE(SUM(oi.price), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.customer_id = c0_.id) AS totalRevenue_3,
       (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c0_.id) AS lastOrderDate_4
FROM customers c0_
```


### QueryBuilder

Works with `QueryBuilder` too:

```php
$customers = $entityManager
    ->createQueryBuilder()
    ->select('c')
    ->from(Customer::class, 'c')
    ->where('c.name LIKE :name')
    ->setParameter('name', '%Acme%')
    ->getQuery()
    ->getResult();
```

And in the repositories too:

```php
class CustomerRepository extends ServiceEntityRepository
{
    public function findTopCustomers(int $limit): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        // $result[0]->totalRevenue is populated automatically
    }
}
```

Methods find(), findBy(), findOneBy() and findAll() are also supported:

```php
$customerRepository = $this->em->getRepository(Customer::class);

$customers = $customerRepository->findAll();

echo $customer[0]->orderCount;    // populated from subquery
echo $customer[0]->totalRevenue;  // populated from subquery
```

### Using formula fields in queries

Formula fields can be used in `WHERE`, `ORDER BY`, `GROUP BY` and `HAVING` clauses
just like regular entity properties:

#### WHERE clause

Filter entities by computed values:

```php
// DQL
$customers = $entityManager
    ->createQuery('SELECT c FROM App\Entity\Customer c WHERE c.orderCount > :minOrders')
    ->setParameter('minOrders', 5)
    ->getResult();

// QueryBuilder
$customers = $entityManager
    ->createQueryBuilder()
    ->select('c')
    ->from(Customer::class, 'c')
    ->where('c.totalRevenue >= :minRevenue')
    ->setParameter('minRevenue', 1000.0)
    ->getQuery()
    ->getResult();

// Repository findBy()
$customers = $customerRepository->findBy(['orderCount' => 10]);
```


#### ORDER BY clause

Sort by formula fields:

```php
// DQL
$customers = $entityManager
    ->createQuery('SELECT c FROM App\Entity\Customer c ORDER BY c.totalRevenue DESC')
    ->getResult();

// QueryBuilder
$customers = $entityManager
    ->createQueryBuilder()
    ->select('c')
    ->from(Customer::class, 'c')
    ->orderBy('c.orderCount', 'DESC')
    ->getQuery()
    ->getResult();

// Repository findBy() with ordering
$customers = $customerRepository->findBy(
    [],
    ['totalRevenue' => 'DESC']
);
```


#### GROUP BY and HAVING clauses

Aggregate and filter by computed values:

```php
// Group customers by order count and filter groups
$result = $entityManager
    ->createQuery('
        SELECT c.orderCount, COUNT(c.id) as customerCount, AVG(c.totalRevenue) as avgRevenue
        FROM App\Entity\Customer c
        GROUP BY c.orderCount
        HAVING c.orderCount >= :minOrders AND COUNT(c.id) > :minCustomers
        ORDER BY c.orderCount DESC
    ')
    ->setParameter('minOrders', 3)
    ->setParameter('minCustomers', 1)
    ->getResult();

// Result example:
// [
//   ['orderCount' => 10, 'customerCount' => 5, 'avgRevenue' => 15000.50],
//   ['orderCount' => 7,  'customerCount' => 3, 'avgRevenue' => 8500.25],
//   ...
// ]
```


#### Combined example

All clauses together in a single query:

```php
$result = $entityManager
    ->createQuery('
        SELECT c.orderCount, COUNT(c.id) as total
        FROM App\Entity\Customer c
        WHERE c.totalRevenue > :minRevenue
        GROUP BY c.orderCount
        HAVING c.orderCount BETWEEN :minOrders AND :maxOrders
        ORDER BY c.orderCount DESC
    ')
    ->setParameter('minRevenue', 500.0)
    ->setParameter('minOrders', 2)
    ->setParameter('maxOrders', 10)
    ->getResult();
```

> **Note:** Formula fields work transparently in all query clauses.
> The SQL subquery is embedded only once per query, not per clause usage.


### Aggregate functions

All DQL aggregate functions (e.g. `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`) work with formula fields out of the box:

```php
$result = $entityManager
    ->createQueryBuilder()
    ->select(
        'SUM(c.orderCount) as totalOrders',
        'AVG(c.totalRevenue) as avgRevenue',
        'MAX(c.totalRevenue) as maxRevenue',
        'MIN(c.totalRevenue) as minRevenue',
    )
    ->from(Customer::class, 'c')
    ->getQuery()
    ->getSingleResult();

// Result example:
// [
//   'totalOrders' => 42,
//   'avgRevenue'  => 1500.50,
//   'maxRevenue'  => 9800.00,
//   'minRevenue'  => 0.0,
// ]
```

> **Note:** `MIN` and `MAX` ignore `NULL` values — so nullable formula fields
> (e.g. `?float $maxOrderTotal`) behave correctly even when some entities
> have no related records.


### Nullable fields

If a formula can return `NULL` (e.g. `MAX` on an empty set),
declare the property as nullable — the type is inferred automatically:

```php
#[Formula('(SELECT MAX(o.total) FROM orders o WHERE o.customer_id = {this}.id)')]
public ?float $maxOrderTotal = null;
```


### The `{this}` placeholder

Use `{this}` to reference the root entity's table alias in the native SQL expression or root entity itself in the DQL expression.

In **native SQL**, `{this}` is resolved to the actual Doctrine-generated table alias (e.g. `c0_`):

```php
// {this} → c0_ (SQL table alias)
#[Formula('(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)')]
public int $orderCount = 0;
```

In **DQL**, `{this}` refers to the root entity itself, so you compare against the entity
reference directly — without a field suffix:

```php
// {this} → the root entity alias
#[Formula('SELECT COUNT(o) FROM App\Entity\Order o WHERE o.customer = {this}')]
public int $orderCount = 0;
```

> **Do not** hardcode the table name or alias directly — it will break when Doctrine
> generates a different alias.

### Custom SELECT alias

By default the SQL column alias matches the property name.
Override it with the `alias` parameter:

```php
#[Formula(
    sql: '(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)',
    alias: 'total_orders',
)]
public int $orderCount = 0;
```


> Use a custom alias only when you need to control the raw SQL column name,
> e.g. for compatibility with a specific reporting tool.

## How it works

1. **`FormulaMetadataFactory`** reads `#[Formula]` attributes via PHP Reflection
   and builds `FormulaMetadata` value objects (SQL, PHP type inferred from type hint,
   alias, nullability).

2. **`FormulaMetadataRegistry`** caches the metadata per entity class — Reflection
   runs only once per class per process.

3. **`LoadClassMetadataListener`** registers formula fields as non-insertable,
   non-updatable mapped fields in Doctrine `ClassMetadata` when entity metadata
   is loaded. This allows the standard `ObjectHydrator` to populate them without
   a custom hydrator, while ensuring they never appear in `INSERT` or `UPDATE`
   statements.

4. **`PostGenerateSchemaListener`** removes formula fields from the generated
   database schema after `SchemaTool` builds it. Formula fields have no physical
   column — their value is computed by a SQL subquery at query time.

5. **`FormulaDoctrineConfigurator`** (a Symfony service configurator) registers
   `FormulaSqlWalker` as the default output walker and passes `FormulaMetadataRegistry`
   as a default query hint into every Doctrine `Configuration` instance.

6. **`FormulaSqlWalker`** (extends `SqlWalker`, implements `OutputWalker`) intercepts
   DQL-to-SQL generation. It scans all DQL aliases in the query — both the root
   entity and any eagerly joined entities — and replaces plain column references
   (e.g. `c0_.orderCount`) with the resolved subquery expressions directly in the
   generated SQL string.

   Supports Walker Chaining: if another output walker was
   already registered, `FormulaSqlWalker` delegates to it first and applies
   formula replacements on top of its output.

7. **`FormulaMiddleware`** (DBAL Middleware) intercepts SQL generated by
   `BasicEntityPersister` for `find()`, `findBy()`, `findAll()`, eager association
   loading and lazy proxy initialisation. It detects all table aliases present in
   the SQL (`t0`, `t1`, `t4`, etc.), matches formula column references for each,
   and replaces them with the resolved subquery expressions.

```
DQL query (createQuery / QueryBuilder / Repository methods)
    │
    ▼
FormulaSqlWalker           — replaces "c0_.orderCount AS orderCount_2" → "(SELECT COUNT(*) ...) AS orderCount_2"
    │
    ▼
Single SQL query executed  — all formula fields in one round-trip
    │
    ▼
ObjectHydrator             — populates formula fields via ClassMetadata fieldMappings
    │
    ▼
Entity with populated formula fields

OR

find() / findAll() / findBy() / lazy proxy
    │
    ▼
BasicEntityPersister       — generates SQL with "t0.orderCount"
    │
    ▼
FormulaMiddleware          — replaces "t0_.orderCount AS orderCount_2" → "(SELECT COUNT(*) ...) AS orderCount_2"
    │
    ▼
Single SQL query executed  — all formula fields in one round-trip
    │
    ▼
ObjectHydrator             — populates formula fields via ClassMetadata fieldMappings
    │
    ▼
Entity with populated formula fields
```


## Limitations

| Limitation             | Notes                                                                                                                                                                                                         |
|------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Read-only fields       | Formula fields must not have `#[ORM\Column]`. They are registered internally by the library and must never be written to the database.                                                                        |
| Scalar types only      | Supported PHP types: `int`, `float`, `string`, `bool` and their nullable variants. Always provide a default value for non-nullable formula properties (e.g. `public int $orderCount = 0`).                    |
| Native SQL             | `$em->getConnection()->executeQuery(...)` bypasses both Walker and Middleware entirely — formula fields will hold their default PHP values.                                                                   |
| Schema Tool            | `doctrine:schema:create` and `doctrine:schema:update` do not create columns for formula fields — they have no physical column in the database. This is correct behaviour.                                     |


## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Testing

``` bash
# All tests
./vendor/bin/phpunit

# Only unit
./vendor/bin/phpunit --testsuite Unit

# Only integration
./vendor/bin/phpunit --testsuite Integration

# Specific file
./vendor/bin/phpunit tests/Unit/DBAL/FormulaConnectionTest.php

# With coating (requires Xdebug or PCOV)
./vendor/bin/phpunit --coverage-text
```

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) and [CODE_OF_CONDUCT](CODE_OF_CONDUCT.md) for details.

## Security

If you discover any security related issues, please email `cryonighter@yandex.ru` instead of using the issue tracker.

## Credits

- [Andrey Reshetchenko][link-author]

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.

[PSR-2]: http://www.php-fig.org/psr/psr-2/
[PSR-4]: http://www.php-fig.org/psr/psr-4/

[ico-version]: https://img.shields.io/packagist/v/cryonighter/formula-doctrine.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/cryonighter/formula-doctrine.svg?style=flat-square

[link-author]: https://github.com/cryonighter
[link-packagist]: https://packagist.org/packages/cryonighter/formula-doctrine
[link-downloads]: https://packagist.org/packages/cryonighter/formula-doctrine
