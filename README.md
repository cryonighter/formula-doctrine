# Formula Doctrine

Hibernate-style `#[Formula]` computed fields for Doctrine ORM 3 entities.

Adds support for read-only, SQL-computed entity properties populated via
subqueries, aggregations and joins — without N+1 queries.

```php
#[Formula('(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)')]
public int $orderCount = 0;
```

## Requirements

- PHP 8.2+
- doctrine/orm ^3.0
- doctrine/dbal ^4.0
- symfony/http-kernel ^6.4 || ^7.0

## Install

Via Composer

```shell script
composer require cryonighter/formula-doctrine
```

Register the bundle in `config/bundles.php`:

```php
return [
    // ...
    Cryonighter\FormulaDoctrine\FormulaDoctrineBundle::class => ['all' => true],
];
```

That's it. No additional configuration is required.

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

    #[Formula('(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)')]
    public int $orderCount = 0;

    #[Formula('(SELECT COALESCE(SUM(oi.price), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE o.customer_id = {this}.id)')]
    public float $totalRevenue = 0.0;

    #[Formula('(SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = {this}.id)')]
    public ?string $lastOrderDate = null;
}
```


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

```sql
SELECT c0_.id,
       c0_.name,
       (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c0_.id) AS orderCount,
       (SELECT COALESCE(SUM(...), 0) FROM ...) AS totalRevenue,
       (SELECT MAX(...) FROM ...) AS lastOrderDate
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


### Repositories

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


### Nullable fields

If a formula can return `NULL` (e.g. `MAX` on an empty set),
declare the property as nullable — the type is inferred automatically:

```php
#[Formula('(SELECT MAX(o.total) FROM orders o WHERE o.customer_id = {this}.id)')]
public ?float $maxOrderTotal = null;
```


### The `{this}` placeholder

Use `{this}` to reference the root entity's table alias in the SQL expression.
It is resolved to the actual Doctrine-generated alias (e.g. `c0_`) at query time.

```php
// {this} will become the real SQL alias, e.g. c0_
#[Formula('(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)')]
public int $orderCount = 0;
```


> **Do not** hardcode the table name directly — it will break when Doctrine
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

2. **`FormulaRegistry`** caches the metadata per entity class — Reflection runs
   only once per class per process.

3. **`LoadClassMetadataListener`** registers formula fields as mapped fields in
   Doctrine `ClassMetadata` when entity metadata is loaded. This allows the standard
   `ObjectHydrator` to populate them without a custom hydrator.

4. **`FormulaDoctrineConfigurator`** (a Symfony service configurator) registers
   `FormulaSqlWalker` as the default output walker and passes `FormulaRegistry`
   as a default query hint into every Doctrine `Configuration` instance.

5. **`FormulaSqlWalker`** (extends `SqlWalker`, implements `OutputWalker`) intercepts
   DQL-to-SQL generation. For each formula field it replaces the plain column reference
   (e.g. `c0_.orderCount`) with the resolved subquery expression directly in the
   generated SQL string.

6. **`FormulaMiddleware`** (DBAL Middleware) intercepts SQL generated by
   `BasicEntityPersister` for `find()`, `findBy()`, `findAll()` and lazy proxy
   initialisation. It replaces formula column references (e.g. `t0.orderCount`)
   with the resolved subquery expressions, using `t0` — the fixed alias always
   used by `BasicEntityPersister`.

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
FormulaMiddleware          — replaces "c0_.orderCount AS orderCount_2" → "(SELECT COUNT(*) ...) AS orderCount_2"
    │
    ▼
Single SQL query executed  — all formula fields in one round-trip
    │
    ▼
Entity with populated formula fields
```


## Limitations

| Limitation             | Notes                                                                                                                                                                                                                                                        |
|------------------------|--------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| Root entity only (DQL) | `FormulaSqlWalker` injects formulas only for the root entity of the DQL `FROM` clause. Non-root entities loaded via JOIN do not get formula fields populated through the Walker — they are handled by `FormulaMiddleware` when initialised lazily via proxy. |
| Read-only              | Formula fields must not have `#[ORM\Column]`. They are registered internally by the library and must never be written to the database.                                                                                                                       |
| Scalar types only      | Supported PHP types: `int`, `float`, `string`, `bool` and their nullable variants.                                                                                                                                                                           |
| Native SQL             | `$em->getConnection()->executeQuery(...)` bypasses both Walker and Middleware entirely — formula fields will hold their default PHP values.                                                                                                                  |
| Schema Tool            | `doctrine:schema:create` and `doctrine:schema:update` do not create columns for formula fields — they have no physical column in the database. This is correct behaviour.                                                                                    |


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
./vendor/bin/phpunit tests/Unit/Query/FormulaSqlWalkerAliasTest.php

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

[link-author]: https://github.com/cryonighter
