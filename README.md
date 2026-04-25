# cryonighter/formula-doctrine

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

3. **`FormulaDoctrineConfigurator`** (a Symfony service configurator) registers
   `FormulaSqlWalker` as the default output walker and passes `FormulaRegistry`
   as a default query hint into every Doctrine `Configuration` instance.

4. **`FormulaSqlWalker`** (extends `SqlWalker`, implements `OutputWalker`) intercepts
   DQL-to-SQL generation. For each formula field it resolves `{this}` to the real
   table alias and injects the SQL expression into the `SELECT` clause.

5. **`FormulaObjectHydrator`** (extends `ObjectHydrator`) reads the extra scalar
   columns from the result row and writes them to the entity properties via
   `ReflectionProperty`, bypassing visibility and `readonly` constraints.

6. **`OnFlushListener`** strips formula fields from the Doctrine UnitOfWork
   changeset before every `flush()`, ensuring they are never written to the database.

```
DQL query
    │
    ▼
FormulaSqlWalker          — adds "(SELECT ...) AS alias" to SELECT clause
    │
    ▼
Single SQL query executed
    │
    ▼
FormulaObjectHydrator     — sets formula property values via Reflection
    │
    ▼
Entity with populated formula fields
```


## Limitations

| Limitation           | Notes                                                                                                                                                                 |
|----------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| DQL only             | Formula fields are populated only when querying via DQL or `QueryBuilder`. Native SQL queries (`$em->getConnection()->executeQuery(...)`) bypass the walker entirely. |
| Root entity only     | Formulas are injected for the root entity of the DQL `FROM` clause. Joined entities with their own formulas require a separate query.                                 |
| Read-only            | Formula fields must not have `#[ORM\Column]`. Attempting to write to them will have no effect on the database.                                                        |
| Scalar types only    | Supported PHP types: `int`, `float`, `string`, `bool` and their nullable variants.                                                                                    |
| SQLite compatibility | `{this}` resolution depends on Doctrine alias generation, which is consistent across all supported DBAL drivers.                                                      |


## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Testing

Все тесты
``` bash
$ php vendor/bin/phpunit tests
```

Только unit тесты
``` bash
$ php vendor/bin/phpunit --testsuite Unit
```

Только  integration тесты
``` bash
$ php vendor/bin/phpunit --testsuite Integration
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



```
---

Это полный `README.md`. Пара замечаний по содержанию:

- **Секция "How it works"** намеренно краткая — для пользователей пакета, не для контрибьюторов. Детальная архитектура уместна в отдельном `ARCHITECTURE.md` при необходимости.

```
