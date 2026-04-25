# Architecture

This document describes the internal architecture of `cryonighter/formula-doctrine`
for contributors and maintainers.

## Table of Contents

- [Overview](#overview)
- [Component Map](#component-map)
- [Data Flow](#data-flow)
- [Component Reference](#component-reference)
  - [Attribute Layer](#attribute-layer)
  - [Metadata Layer](#metadata-layer)
  - [Query Layer](#query-layer)
  - [Hydration Layer](#hydration-layer)
  - [Event Listener Layer](#event-listener-layer)
  - [Symfony Integration Layer](#symfony-integration-layer)
- [Key Design Decisions](#key-design-decisions)
- [Extension Points](#extension-points)
- [Known Limitations and Edge Cases](#known-limitations-and-edge-cases)

---

## Overview

The package intercepts Doctrine ORM's DQL-to-SQL pipeline at two points:

1. **SQL generation** — `FormulaSqlWalker` adds subquery expressions to the
   `SELECT` clause and registers them in `ResultSetMapping`.
2. **Hydration** — `FormulaObjectHydrator` reads the scalar values from the
   result row and writes them to entity properties via Reflection.

Everything is wired automatically through a Symfony service configurator and
Doctrine event listeners. The end user only needs to add `#[Formula]` to a
property — no query changes required.

---

## Component Map
```

src/
├── Attribute/
│   └── Formula.php                         # Public API: the #[Formula] attribute
│
├── Mapping/
│   └── FormulaMetadata.php                 # Value object: one formula field descriptor
│
├── Metadata/
│   ├── FormulaMetadataFactory.php          # Reads #[Formula] via PHP Reflection
│   └── FormulaRegistry.php                 # Session-scoped cache: class → metadata[]
│
├── Query/
│   └── FormulaSqlWalker.php                # Extends SqlWalker: injects SQL + registers RSM
│
├── Hydration/
│   └── FormulaObjectHydrator.php           # Extends ObjectHydrator: writes values via Reflection
│
├── EventListener/
│   ├── LoadClassMetadataListener.php       # Warms up FormulaRegistry on Doctrine boot
│   └── OnFlushListener.php                 # Strips formula fields from UnitOfWork changeset
│
├── DependencyInjection/
│   ├── FormulaDoctrineCompilerPass.php     # Attaches configurator to ORM Configuration services
│   └── FormulaDoctrineConfigurator.php     # Sets default query hints on ORM Configuration
│
└── FormulaDoctrineBundle.php               # Bundle entry point
```
---

## Data Flow

### Boot / Cache Warm-up
```

Symfony Kernel Boot
│
▼
FormulaDoctrineCompilerPass::process()
└── Sets FormulaDoctrineConfigurator as configurator
on every "doctrine.orm.*_configuration" DI service
│
▼
FormulaDoctrineConfigurator::configure(Configuration $config)
├── $config->addCustomHydrationMode('formula_object', FormulaObjectHydrator::class)
├── $config->setDefaultQueryHint(HINT_CUSTOM_OUTPUT_WALKER, FormulaSqlWalker::class)
└── $config->setDefaultQueryHint(HINT_REGISTRY, FormulaRegistry $instance)
│
▼
Doctrine loads entity metadata (loadClassMetadata event)
└── LoadClassMetadataListener::loadClassMetadata()
└── FormulaRegistry::getForClass($class)
└── FormulaMetadataFactory::createForClass($class)
└── ReflectionClass → reads #[Formula] attributes
→ builds FormulaMetadata[]
→ cached in FormulaRegistry
```
### Query Execution
```

User code: $em->createQuery('SELECT p FROM Product p')->getResult()
│
▼
Doctrine DQL Parser → AST
│
▼
FormulaSqlWalker::walkSelectStatement(SelectStatement $ast)
├── parent::walkSelectStatement()        # standard SQL generation
├── reads FormulaRegistry from HINT_REGISTRY
├── resolves root DQL alias ('p') → SQL table alias ('p0_')
├── for each FormulaMetadata:
│   ├── str_replace('{this}', 'p0_', $meta->sql)  → resolved SQL
│   ├── $rsm->addScalarResult($alias, $alias, $phpType)
│   └── appends "(resolved SQL) AS alias" to SELECT
├── $query->setHint(HINT_FORMULA_MAP, [alias => FormulaMetadata])
└── injectBeforeFrom($sql, $formulaFragments)  → final SQL string
│
▼
Final SQL:
SELECT p0_.id, p0_.name,
(SELECT COUNT(*) FROM orders o WHERE o.customer_id = p0_.id) AS orderCount,
(SELECT SUM(...) FROM ...) AS totalRevenue
FROM products p0_
│
▼
FormulaObjectHydrator::hydrateAllData()
├── parent::hydrateAllData()
│   └── returns mixed result:
│       [ [0 => Product{id:1,name:'A'}, 'orderCount' => '5', 'totalRevenue' => '99.9'], ... ]
├── reads HINT_FORMULA_MAP
├── for each row:
│   ├── $entity = $row[0]
│   ├── for each formula alias in $row:
│   │   ├── castValue($row[$alias], $meta)  → typed PHP value
│   │   └── ReflectionProperty::setValue($entity, $value)
│   └── collects $entity into clean result array
└── returns [ Product{orderCount:5, totalRevenue:99.9}, ... ]
│
▼
User receives: Product[] with formula fields populated
```
### Flush
```

$em->flush()
│
▼
Doctrine computes changesets for all managed entities
│
▼
OnFlushListener::onFlush(OnFlushEventArgs $args)
├── iterates getScheduledEntityUpdates() + getScheduledEntityInsertions()
└── for each entity with formula fields:
├── reads UnitOfWork::getEntityChangeSet($entity)
├── unsets formula property names from the changeset
├── $uow->clearEntityChangeSet(spl_object_id($entity))
└── if non-formula changes remain:
└── $uow->recomputeSingleEntityChangeSet($classMetadata, $entity)
│
▼
Doctrine flushes only real column changes — formula fields never reach SQL
```
---

## Component Reference

### Attribute Layer

#### `Formula` (`src/Attribute/Formula.php`)

The only public-facing API of the package.

| Parameter | Type | Description |
|---|---|---|
| `$sql` | `string` | Native SQL expression. Use `{this}` for the root table alias. |
| `$alias` | `?string` | SQL column alias in `SELECT`. Defaults to property name. |

PHP type and nullability are inferred from the property type hint at metadata
scan time. Supported types: `int`, `float`, `string`, `bool` and nullable variants.

---

### Metadata Layer

#### `FormulaMetadata` (`src/Mapping/FormulaMetadata.php`)

Immutable value object. Produced by `FormulaMetadataFactory`, stored in
`FormulaRegistry`. Consumed by `FormulaSqlWalker` and `FormulaObjectHydrator`.

Fields:

| Field | Source |
|---|---|
| `entityClass` | FQCN of the owning entity |
| `propertyName` | PHP property name |
| `sql` | Raw SQL with `{this}` placeholder |
| `phpType` | Inferred from `ReflectionNamedType::getName()` |
| `nullable` | Inferred from `ReflectionType::allowsNull()` |
| `alias` | `Formula::$alias` ?? `$propertyName` |

#### `FormulaMetadataFactory` (`src/Metadata/FormulaMetadataFactory.php`)

Stateless service. Uses `ReflectionClass` to scan all properties of a class
for `#[Formula]` attributes. Returns `list<FormulaMetadata>`.

Called **once per class** per process — subsequent calls are served from
`FormulaRegistry` cache.

#### `FormulaRegistry` (`src/Metadata/FormulaRegistry.php`)

Session-scoped in-memory cache. Stores `class-string → list<FormulaMetadata>`.
Also tracks which classes have been scanned (including those with no formulas)
to avoid redundant Reflection calls.

---

### Query Layer

#### `FormulaSqlWalker` (`src/Query/FormulaSqlWalker.php`)

Extends `Doctrine\ORM\Query\SqlWalker`, implements `Doctrine\ORM\Query\OutputWalker`.

Registered globally as the default output walker via
`Configuration::setDefaultQueryHint(Query::HINT_CUSTOM_OUTPUT_WALKER, ...)`.

**Key responsibilities:**

1. Detect root entity class from the DQL `FROM` clause.
2. Resolve the Doctrine SQL table alias for the root entity via
   `getSQLTableAlias($tableName, $dqlAlias)`.
3. Replace `{this}` with the real alias in each formula's SQL.
4. Append `(resolved SQL) AS alias` fragments to the SELECT clause via
   string injection before `FROM`.
5. Register each formula as a scalar result in `ResultSetMapping` via
   `$rsm->addScalarResult($alias, $alias, $phpType)` — this is what causes
   Doctrine to include the value in the result row.
6. Store `alias → FormulaMetadata` map in `HINT_FORMULA_MAP` query hint for
   the hydrator to read.

**Why string injection instead of AST manipulation?**

Adding a node to the DQL AST `SelectClause` at the SqlWalker stage is
fragile — the AST is partially processed by this point and injected
nodes may not resolve correctly. String injection after
`parent::walkSelectStatement()` is the same approach used by other
Doctrine extensions (e.g. `LimitSubqueryOutputWalker`).

**Query hints used:**

| Hint key | Direction | Content |
|---|---|---|
| `HINT_CUSTOM_OUTPUT_WALKER` | → Walker | `FormulaSqlWalker::class` (string) |
| `HINT_REGISTRY` | → Walker | `FormulaRegistry` instance |
| `HINT_FORMULA_MAP` | Walker → Hydrator | `array<alias, FormulaMetadata>` |

---

### Hydration Layer

#### `FormulaObjectHydrator` (`src/Hydration/FormulaObjectHydrator.php`)

Extends `Doctrine\ORM\Internal\Hydration\ObjectHydrator`.

Registered as a custom hydration mode under the name `formula_object`
via `Configuration::addCustomHydrationMode(...)`.

**Why extend `ObjectHydrator` instead of overriding `hydrateRowData`?**

`ObjectHydrator::hydrateRowData` has complex internal state (`_resultPointers`,
identity map, association handling). Its `$result` parameter is not a flat
array of objects — it is an internal accumulator. Overriding it safely would
require duplicating Doctrine internals.

Instead, we override `hydrateAllData()`:

1. Call `parent::hydrateAllData()` — which returns a **mixed result** because
   `addScalarResult` was called. Mixed result rows have the shape:
   `[0 => EntityObject, 'alias' => rawValue, ...]`
2. Iterate over rows, extract `$row[0]` as the entity, read scalar aliases,
   cast values, write via `ReflectionProperty::setValue()`.
3. Return a clean `EntityObject[]`.

**`ReflectionProperty::setValue()` and `readonly`:**

`setAccessible(true)` is called once per property and cached. This allows
writing to both `private` and `readonly` properties — necessary since formula
fields are naturally `readonly` (they should never be set from user code).

---

### Event Listener Layer

#### `LoadClassMetadataListener` (`src/EventListener/LoadClassMetadataListener.php`)

Listens to `loadClassMetadata` (priority 10). Calls
`FormulaRegistry::getForClass()` for each entity class as Doctrine loads its
metadata. This pre-warms the registry so the first query has no Reflection
cold start.

#### `OnFlushListener` (`src/EventListener/OnFlushListener.php`)

Listens to `onFlush`. Iterates `getScheduledEntityUpdates()` and
`getScheduledEntityInsertions()`, removes formula property names from each
entity's UnitOfWork changeset.

**Why `onFlush` and not `preFlush`?**

`preFlush` fires before changeset computation. At that point there is nothing
to remove yet. `onFlush` fires after `computeChangeSets()`, so the changeset
is populated and can be modified.

**Changeset removal sequence:**
```

1. $uow->clearEntityChangeSet(spl_object_id($entity))
   — wipes the entire changeset for this entity

2a. If no real changes remain → scheduleForUpdate + clearEntityChangeSet again
to prevent a no-op UPDATE statement.

2b. If real changes remain → $uow->recomputeSingleEntityChangeSet($meta, $entity)
— Doctrine re-diffs originalData vs current state, producing a changeset
without the formula fields (since they have no column mapping).
```
---

### Symfony Integration Layer

#### `FormulaDoctrineConfigurator` (`src/DependencyInjection/FormulaDoctrineConfigurator.php`)

A [Symfony service configurator](https://symfony.com/doc/current/service_container/configurators.html).
Called by the DI container after each `Doctrine\ORM\Configuration` service
is instantiated.

Injects:
- Custom hydration mode registration
- Default query hint: `HINT_CUSTOM_OUTPUT_WALKER = FormulaSqlWalker::class`
- Default query hint: `HINT_REGISTRY = FormulaRegistry` (real object, not a reference)

The configurator approach is used instead of `CompilerPass::addMethodCall` +
`new Reference(...)` because `setDefaultQueryHint` stores a plain value —
a DI `Reference` object would be stored as-is, not resolved. The configurator
receives the already-resolved `FormulaRegistry` instance at runtime.

#### `FormulaDoctrineCompilerPass` (`src/DependencyInjection/FormulaDoctrineCompilerPass.php`)

Finds all `doctrine.orm.*_configuration` services in the DI container and
attaches `FormulaDoctrineConfigurator::configure` as a Symfony service
configurator to each of them. Supports multiple entity managers.

#### `FormulaDoctrineBundle` (`src/FormulaDoctrineBundle.php`)

Entry point. Registers the compiler pass in `build()`. Loads
`config/services.yaml` in `loadExtension()`.

---

## Key Design Decisions

### 1. No `postLoad` event for hydration

`postLoad` was the "easy" approach — set property values after entity load.
It was rejected because:
- It causes N+1 queries when used with separate SQL per entity.
- It fires before associations are fully populated in some Doctrine versions.
- Our `FormulaSqlWalker` already puts formula values in the result row;
  `postLoad` would require a second round-trip or in-memory lookup.

### 2. `ResultSetMapping::addScalarResult` as the contract between Walker and Hydrator

The Walker registers formula columns as scalars in RSM. This causes Doctrine
to produce a "mixed" result (`[0 => entity, 'alias' => value]`). The Hydrator
then unwraps it. This is a clean, well-defined contract that avoids parsing
the raw SQL result row manually.

### 3. `hydrateAllData` override instead of `hydrateRowData`

`hydrateRowData` operates on Doctrine's internal accumulator state.
`hydrateAllData` returns the final, fully-built result — safe to post-process.

### 4. `setConfigurator` instead of `addMethodCall` + `Reference`

`Configuration::setDefaultQueryHint` stores a plain PHP value.
Passing `new Reference('service_id')` via `addMethodCall` would store the
`Reference` object itself — Doctrine has no DI container awareness.
The service configurator pattern resolves the dependency before calling
`configure()`, delivering the real `FormulaRegistry` object.

### 5. `{this}` placeholder instead of raw table name

Users cannot know the Doctrine-generated SQL alias at annotation time.
The `{this}` placeholder is resolved at query time by `FormulaSqlWalker`
via `getSQLTableAlias($tableName, $dqlAlias)`.

---

## Extension Points

### Custom type casting

Override `FormulaObjectHydrator::castValue()` and register your subclass
as the `formula_object` hydration mode in `FormulaDoctrineConfigurator`.

### Selective formula loading

Formulas are always loaded. A future `fetchEager: false` flag on `#[Formula]`
could allow opt-in loading via an explicit query hint — the architecture
supports this: `FormulaSqlWalker` can check per-field metadata before injecting.

### Multiple entity managers

`FormulaDoctrineCompilerPass` iterates all `doctrine.orm.*_configuration`
services, so multiple entity managers are supported out of the box.

---

## Known Limitations and Edge Cases

### DQL only

Formula injection happens in `FormulaSqlWalker`, which processes DQL queries.
Native SQL (`$em->getConnection()->executeQuery(...)`) bypasses the walker
entirely — formula fields will have their default PHP values.

### Root entity only

Formulas are resolved for the root entity of the DQL `FROM` clause. If a
joined entity also has `#[Formula]` fields, they are not populated in the
same query. A separate query for that entity will populate them correctly.

### Doctrine query cache

`setDefaultQueryHint(HINT_FORMULA_MAP, ...)` is set at SQL generation time and
is part of the query cache key. If query caching is enabled, the cached SQL
already includes formula fragments — no regeneration needed.

However, `HINT_FORMULA_MAP` itself contains `FormulaMetadata` objects. These
are not serializable by default. If you enable query result caching, ensure
`FormulaMetadata` does not end up in the result cache.

### SQLite and aggregate functions

SQLite supports `COUNT`, `SUM`, `MAX`, `MIN`, `AVG` and subqueries.
Tests use SQLite in-memory for CI. Production MySQL/PostgreSQL/MariaDB
behavior is identical — formula SQL is passed through verbatim.

### `readonly` properties

Formula properties declared as `readonly` are written via
`ReflectionProperty::setValue()`. In PHP 8.2+ this works on uninitialized
readonly properties. If the property has already been initialized (e.g. via
constructor), a subsequent `setValue` will throw `Error: Cannot modify
readonly property`. Initialize readonly formula properties with a default
value instead of setting them in the constructor:
```
php
// Correct
#[Formula('(SELECT COUNT(*) FROM orders o WHERE o.customer_id = {this}.id)')]
public readonly int $orderCount = 0;

// Will throw on hydration if already set in constructor
public function __construct() {
$this->orderCount = 0; // ← do not do this
}
```

### Paginator compatibility

Doctrine's `Paginator` class may apply its own output walker
(`LimitSubqueryOutputWalker`) which conflicts with `FormulaSqlWalker`.
When using `Paginator`, set `$paginator->setUseOutputWalkers(false)` or
ensure formula fields are not required in paginated count queries.

### `GROUP BY` and formula columns

If a DQL query uses `GROUP BY`, the injected formula subqueries appear in
`SELECT` but not in `GROUP BY`. This is correct — subquery expressions in
`SELECT` do not need to be grouped. However, if a formula references an
aggregate that conflicts with the outer `GROUP BY`, the SQL may be rejected
by the database engine. In such cases write the formula SQL accordingly.

### Schema Tool

`doctrine:schema:create` and `doctrine:schema:update` do not see formula
fields as columns — they have no `#[ORM\Column]` mapping. This is correct
behaviour. Formula fields never produce DDL.

---

## Testing Strategy

Tests are split into two suites.

### Unit Suite (`tests/Unit/`)

Tests individual components in isolation without Doctrine infrastructure.

| Test | Subject | Approach |
|---|---|---|
| `FormulaTest` | `#[Formula]` attribute | Direct instantiation |
| `FormulaMetadataFactoryTest` | Reflection scan | Inline fixture classes defined in the test file |
| `FormulaRegistryTest` | Caching logic | Inline fixture classes |
| `FormulaSqlWalkerAliasTest` | `resolvePlaceholder`, `injectBeforeFrom` | `FormulaSqlWalkerProxy` bypasses `SqlWalker` constructor via `ReflectionClass::newInstanceWithoutConstructor()` |

`SqlWalker` cannot be instantiated without a live `EntityManager`. The proxy
class exposes `protected` methods under test without constructing the walker.

### Integration Suite (`tests/Integration/`)

Runs against a real SQLite in-memory database bootstrapped in `OrmTestCase`.

`OrmTestCase` wires the full stack manually — no Symfony kernel:
```

ORMSetup::createAttributeMetadataConfiguration()
→ Configuration
→ FormulaDoctrineConfigurator::configure($config)   ← our stack
→ DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true])
→ EntityManager
→ SchemaTool::createSchema()
```
This ensures integration tests verify the real end-to-end behaviour
(Walker injects SQL → DB executes subqueries → Hydrator populates properties)
without requiring a running MySQL or PostgreSQL instance.

| Test | What it verifies |
|---|---|
| `testFormulaFieldDefaultsToZeroWhenNoOrders` | Subquery returning `NULL` / `0` is cast correctly |
| `testFormulaCountIsCorrect` | `COUNT` subquery value matches inserted rows |
| `testFormulaSumIsCorrect` | `SUM` subquery value matches arithmetic |
| `testNullableFormulaReturnsNullWhenNoData` | `?float` stays `null` |
| `testNullableFormulaReturnsValueWhenDataExists` | `MAX` populates `?float` |
| `testCollectionQueryUsesNoNPlusOneQueries` | All entities loaded in one SQL |
| `testFormulaFieldsAreNotPersistedOnFlush` | `flush()` after edit does not break |
| `testFormulaFieldChangeDoesNotTriggerUpdate` | Forced property change does not produce `UPDATE` |

---

## Sequence Diagram (full request lifecycle)
```

User                EntityManager       FormulaSqlWalker      DB          FormulaObjectHydrator
│                       │                     │               │                   │
│  createQuery(DQL)     │                     │               │                   │
│──────────────────────▶│                     │               │                   │
│                       │  walkSelectStatement │               │                   │
│                       │────────────────────▶│               │                   │
│                       │                     │ getSQLTableAlias                   │
│                       │                     │ resolvePlaceholder                 │
│                       │                     │ addScalarResult                    │
│                       │                     │ injectBeforeFrom                   │
│                       │◀────────────────────│               │                   │
│                       │  final SQL          │               │                   │
│                       │──────────────────────────────────────▶                  │
│                       │                     │    result rows│                   │
│                       │◀──────────────────────────────────────                  │
│                       │  hydrateAllData()   │               │                   │
│                       │──────────────────────────────────────────────────────── ▶│
│                       │                     │               │  parent hydrates  │
│                       │                     │               │  mixed result     │
│                       │                     │               │  cast + Reflection│
│                       │◀────────────────────────────────────────────────────────│
│  Entity[] with        │                     │               │                   │
│  formula fields       │                     │               │                   │
│◀──────────────────────│                     │               │                   │
```
---

## File Dependency Graph
```

FormulaDoctrineBundle
└── FormulaDoctrineCompilerPass
└── FormulaDoctrineConfigurator
├── FormulaRegistry
│       └── FormulaMetadataFactory
│               └── FormulaMetadata
│                       └── Formula  (attribute)
├── FormulaSqlWalker
│       └── FormulaRegistry
└── FormulaObjectHydrator
└── FormulaMetadata

LoadClassMetadataListener
└── FormulaRegistry

OnFlushListener
└── FormulaRegistry
```

```

