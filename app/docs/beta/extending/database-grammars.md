# Custom Database Grammars

Every piece of SQL that differs between database engines — how an identifier is quoted, how you ask for a random row, how `INSERT` is spelled, how a transaction opens, how a column's DDL is phrased — is isolated behind a `Grammar` class. If you need to talk to a database the framework doesn't ship a grammar for (Firebird, SQL Server, DuckDB, …), or you want a **fuller** version of a built-in one (the shipped `PostgresGrammar` is explicitly foundation-only), you extend `Grammar` and register it. This page covers writing that class; for the three built-in grammars, connection configuration, and driver resolution details, see [Database Drivers & Grammars](../database/drivers).

## Table of Contents

- [The Grammar Contract](#the-grammar-contract)
- [Worked Example: A Firebird Grammar](#worked-example-a-firebird-grammar)
  - [Identifiers](#identifiers)
  - [Dialect Functions](#dialect-functions)
  - [INSERT Syntax](#insert-syntax)
  - [Transactions & Locking](#transactions--locking)
  - [Schema / DDL](#schema--ddl)
- [Registering Your Grammar](#registering-your-grammar)
  - [Programmatic — `GrammarFactory::extend()`](#programmatic--grammarfactoryextend)
  - [Config — `database.grammars`](#config--databasegrammars)
- [Resolution Order](#resolution-order)
- [Overriding a Built-in Grammar](#overriding-a-built-in-grammar)
- [Gotchas](#gotchas)
- [Related](#related)

---

## The Grammar Contract

`Eyika\Atom\Framework\Support\Database\Grammars\Grammar` is an abstract class. `Connection` (the query layer) and `Schema`/`Blueprint` (the DDL layer) both call into whatever grammar instance was resolved for the active driver — they never hardcode dialect SQL themselves.

Six methods are **abstract** — every grammar must implement them:

| Method | Purpose |
|---|---|
| `wrapValue(string $value): string` | Quote one bare identifier segment, doubling any embedded quote character. |
| `random(): string` | The `ORDER BY` expression for a random row. |
| `typeMap(): array` | Portable column type (`'string'`, `'bigInteger'`, `'json'`, …) → this dialect's base SQL type. |
| `compileAlter(Blueprint $blueprint): array` | The dialect's `ALTER TABLE` strategy — returns one or more statements. |
| `compileTableExists(string $table): string` | Catalog query returning rows iff the table exists. |
| `compileColumnExists(string $table, string $column): string` | Catalog query returning rows iff the column exists. |

Everything else on `Grammar` is a **concrete method with a sensible default** (usually MySQL's historical behaviour, or ANSI-standard SQL) that you override only where your dialect actually differs. That's most of the surface a grammar has — you're filling in gaps, not implementing dozens of methods from scratch.

> The full method-by-method reference — grouped by identifiers, dialect functions, INSERT, transactions/locking, and schema/DDL — is documented in [The Grammar Layer](../database/drivers#the-grammar-layer). This page won't repeat that catalogue; skim it alongside the worked example below.

---

## Worked Example: A Firebird Grammar

Say you need to support Firebird. Start from the abstract base and fill in what differs from the defaults, checking each concrete method against how Firebird actually behaves.

```php
<?php

namespace App\Database\Grammars;

use Eyika\Atom\Framework\Support\Database\Grammars\Grammar;
use Eyika\Atom\Framework\Support\Database\Schema\Blueprint;
use Eyika\Atom\Framework\Support\Database\Schema\ColumnDefinition;

class FirebirdGrammar extends Grammar
{
    // filled in below
}
```

### Identifiers

`wrapValue()` is the **SQL-injection defence** for every column/table name interpolated into SQL — `where()` columns, `ORDER BY`/`JOIN` targets, everything `wrap()`/`wrapTable()` touch. It must wrap in the dialect's quote character and double any embedded quote so a crafted identifier can never break out of the quoting:

```php
public function wrapValue(string $value): string
{
    // Firebird uses standard double-quote identifier quoting.
    return '"' . str_replace('"', '""', $value) . '"';
}
```

You don't need to override `wrap()` or `wrapTable()` unless your dialect needs something beyond dotted `table.column` handling (`wrap()`) or a schema prefix on every table reference (`wrapTable()`) — both have working defaults built on `wrapValue()`.

### Dialect Functions

```php
public function random(): string
{
    return 'RAND()'; // Firebird's random-order function
}
```

`now()` (default `'now()'`) and `currentTimestamp()` (default `'current_timestamp'`) are only worth overriding if your dialect spells them differently — Firebird accepts both defaults as-is, so this grammar leaves them alone.

### INSERT Syntax

Every dialect except MySQL compiles the standard `INSERT INTO t (cols) VALUES (...)` form via the base `compileInsert()` — you don't override that method itself unless the whole statement shape differs. What you do override:

```php
// Firebird has no MySQL-style INSERT ... SET; supportsInsertSetSyntax() already
// defaults to false, so nothing to override here.

public function insertKeyword(bool $ignore): string
{
    // Firebird 3+ ignore-duplicate insert; adjust to whatever your target version supports.
    return 'INSERT' . ($ignore ? ' OR IGNORE' : '');
}

public function compileIf(string $condition, string $then, string $else): string
{
    // Base default is already CASE WHEN ... END, which Firebird supports natively —
    // only override this if your dialect has a dedicated IF()-style function.
    return "CASE WHEN {$condition} THEN {$then} ELSE {$else} END";
}
```

> If your dialect *does* have a MySQL-style `INSERT ... SET col = val` form, return `true` from `supportsInsertSetSyntax()` — `Connection::insert()`/`insert_update()` branch on it. Otherwise leave the default `false` and rely on `compileInsert()`.

### Transactions & Locking

```php
public function compileBeginTransaction(): string
{
    return 'START TRANSACTION'; // ANSI default already matches Firebird — shown for clarity
}

public function compileForUpdate(): string
{
    return ' WITH LOCK'; // Firebird's row-lock suffix; the ANSI default is ' FOR UPDATE'
}
```

If your engine has no row-level locking at all (like SQLite), return an empty string from `compileForUpdate()` instead — a `lock: true` read then falls back to whatever transaction isolation the engine provides.

### Schema / DDL

This is the bulk of a new grammar. Start with the type map — every portable column type your migrations will use:

```php
protected function typeMap(): array
{
    return [
        'bigInteger' => 'BIGINT', 'integer' => 'INTEGER', 'string' => 'VARCHAR', 'char' => 'CHAR',
        'text' => 'BLOB SUB_TYPE TEXT', 'tinyText' => 'VARCHAR(255)', 'mediumText' => 'BLOB SUB_TYPE TEXT',
        'longText' => 'BLOB SUB_TYPE TEXT', 'json' => 'BLOB SUB_TYPE TEXT', 'decimal' => 'DECIMAL',
        'timestamp' => 'TIMESTAMP', 'dateTime' => 'TIMESTAMP', 'blob' => 'BLOB', 'tinyBlob' => 'BLOB',
        'mediumBlob' => 'BLOB', 'longBlob' => 'BLOB',
    ];
}
```

Any portable type you leave out of the map falls back to `strtoupper($column->type)` (see `Grammar::typeWithParams()`), so an incomplete map doesn't hard-fail — it just emits a probably-wrong type name. Fill in every type your app's migrations actually use.

Then the auto-increment phrasing (Firebird uses a generator/sequence rather than an inline column keyword, but the simplified `IDENTITY` form below is enough to illustrate the override point):

```php
protected function autoIncrementSql(ColumnDefinition $c): string
{
    $base = $c->type === 'bigInteger' ? 'BIGINT' : 'INTEGER';
    return $base . ' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY';
}
```

Whether secondary indexes are declared inline in `CREATE TABLE` (MySQL) or as separate `CREATE INDEX` statements after it (SQLite, Postgres):

```php
protected function indexesInline(): bool
{
    return false; // Firebird has no inline INDEX clause in CREATE TABLE
}
```

Table/column introspection, backing `Schema::hasTable()` / `Schema::columnExists()`:

```php
public function compileTableExists(string $table): string
{
    return "SELECT 1 FROM rdb\$relations WHERE rdb\$relation_name = '"
        . str_replace("'", "''", strtoupper($table)) . "'";
}

public function compileColumnExists(string $table, string $column): string
{
    return "SELECT 1 FROM rdb\$relation_fields WHERE rdb\$relation_name = '"
        . str_replace("'", "''", strtoupper($table)) . "' AND rdb\$field_name = '"
        . str_replace("'", "''", strtoupper($column)) . "'";
}
```

And finally `compileAlter()` — the one abstract method with no shared default, because every dialect's `ALTER TABLE` capabilities differ enough that there's nothing sensible to inherit. Look at `MySqlGrammar::compileAlter()` (one comma-joined statement) versus `SqliteGrammar::compileAlter()` (per-operation statements, plus a full table rebuild for a `change()`d column) as the two ends of the spectrum, and build whatever your engine actually supports:

```php
public function compileAlter(Blueprint $blueprint): array
{
    $table = $blueprint->getTable();
    $statements = [];

    foreach ($blueprint->getColumns() as $col) {
        if ($col instanceof ColumnDefinition && $col->isChange) {
            // Firebird: ALTER TABLE t ALTER COLUMN c TYPE ...
            $statements[] = 'ALTER TABLE ' . $this->wrapTable($table)
                . ' ALTER COLUMN ' . $this->wrapValue($col->name) . ' TYPE ' . $this->getTypeForAlter($col);
            continue;
        }
        $sql = $col instanceof ColumnDefinition ? $this->compileColumn($col) : (string) $col;
        $statements[] = 'ALTER TABLE ' . $this->wrapTable($table) . ' ADD ' . $sql;
    }
    foreach ($blueprint->getDropColumns() as $col) {
        $statements[] = 'ALTER TABLE ' . $this->wrapTable($table) . ' DROP ' . $this->wrapValue($col);
    }
    // ...rename columns, indexes, foreign keys as your dialect supports them.

    return $statements;
}
```

If a capability genuinely isn't implemented yet (the shipped `PostgresGrammar` does exactly this for column `change()`), throw rather than silently emitting wrong SQL:

```php
throw new \RuntimeException('Firebird column change() is not implemented yet.');
```

---

## Registering Your Grammar

A `Grammar` subclass does nothing until `GrammarFactory` knows to hand it out for a driver key. There are two ways to register one — mirroring how a custom auth guard is wired in `config/auth.php`.

### Programmatic — `GrammarFactory::extend()`

```php
use Eyika\Atom\Framework\Support\Database\Grammars\GrammarFactory;
use App\Database\Grammars\FirebirdGrammar;

GrammarFactory::extend('firebird', FirebirdGrammar::class);

// or a zero-arg factory closure, e.g. if construction needs a bit of setup:
GrammarFactory::extend('firebird', fn () => new FirebirdGrammar());
```

Call this from a service provider's `register()` (or the console/HTTP kernel) — **before any `Connection` is constructed**. `Connection` resolves its grammar eagerly in its constructor, so registering after the app's first connection has already been built has no effect on that connection:

```php
<?php

namespace App\Providers;

use Eyika\Atom\Framework\Foundation\ServiceProvider;
use Eyika\Atom\Framework\Support\Database\Grammars\GrammarFactory;
use App\Database\Grammars\FirebirdGrammar;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        GrammarFactory::extend('firebird', FirebirdGrammar::class);
    }
}
```

`extend($driver, $grammar)` accepts either a `Grammar` class-string (instantiated with `new $grammar()`) or a zero-arg callable returning a `Grammar` instance.

### Config — `database.grammars`

If you'd rather not touch a provider, add a `driver => Grammar class-string` map to `config('database')`, keyed the same way as `connections`:

```php
// config/database.php
return [
    'default' => 'firebird',

    'connections' => [
        'firebird' => [
            'driver'   => 'firebird',
            'host'     => env('DB_HOST'),
            'database' => env('DB_DATABASE'),
        ],
    ],

    'grammars' => [
        'firebird' => \App\Database\Grammars\FirebirdGrammar::class,
    ],
];
```

Either way, the class **must** extend `Grammar` — `GrammarFactory` verifies this and throws an `InvalidArgumentException` (`"Grammar registered for driver [...] must extend Grammar."`) if it doesn't.

---

## Resolution Order

`GrammarFactory::make($driver)` — called once, from inside `Connection`'s constructor — checks three places in order and returns the first match:

1. **`extend()` registrations** — a service provider's `register()`/`boot()`, or the console/HTTP kernel.
2. **`config('database.grammars')`** — the `['<driver>' => Grammar::class]` map.
3. **The built-in grammars** — `mysql`/`mariadb` → `MySqlGrammar`, `sqlite` → `SqliteGrammar`, `pgsql`/`postgres`/`postgresql` → `PostgresGrammar`.

If nothing matches at any of the three steps, it throws:

```
InvalidArgumentException: No SQL grammar registered for database driver [foo].
Register one with GrammarFactory::extend('foo', YourGrammar::class) or via config('database.grammars').
```

An `extend()` call always wins even over a matching `database.grammars` entry for the same driver key — useful when you want to force an override regardless of what config says (e.g. in tests, paired with `GrammarFactory::flushExtensions()` to reset registrations between cases).

---

## Overriding a Built-in Grammar

Because resolution checks `extend()`/config **before** falling through to the built-ins, you can replace a shipped grammar entirely — not just add a new driver key. This is the intended path if you need a fuller Postgres than the shipped foundation-only `PostgresGrammar` (no upsert, no column `change()` — see [PostgreSQL — Foundation Grammar](../database/drivers#postgresql--foundation-grammar)):

```php
<?php

namespace App\Database\Grammars;

use Eyika\Atom\Framework\Support\Database\Grammars\PostgresGrammar;
use Eyika\Atom\Framework\Support\Database\Schema\Blueprint;
use Eyika\Atom\Framework\Support\Database\Schema\ColumnDefinition;

/** Extends the shipped foundation grammar rather than starting from Grammar directly. */
class FullPostgresGrammar extends PostgresGrammar
{
    public function insertKeyword(bool $ignore): string
    {
        // Still plain INSERT — ON CONFLICT is appended by a custom compileInsert() override
        // (or handled in your own Connection::insert_update() call site), not the keyword itself.
        return 'INSERT';
    }

    public function compileAlter(Blueprint $blueprint): array
    {
        $statements = parent::compileAlter($blueprint);

        foreach ($blueprint->getColumns() as $col) {
            if ($col instanceof ColumnDefinition && $col->isChange) {
                $statements[] = 'ALTER TABLE ' . $this->wrapTable($blueprint->getTable())
                    . ' ALTER COLUMN ' . $this->wrapValue($col->name)
                    . ' TYPE ' . $this->getType($col);
            }
        }

        return $statements;
    }
}
```

Then register it against the **same** `pgsql` driver key — either form works:

```php
// Programmatic, in a provider's register():
GrammarFactory::extend('pgsql', FullPostgresGrammar::class);

// or config('database.grammars'):
'grammars' => [
    'pgsql' => \App\Database\Grammars\FullPostgresGrammar::class,
],
```

`GrammarFactory::make('pgsql')` now returns your subclass everywhere the framework asks for the Postgres grammar, no other code needs to change. You can extend the built-in class (as above, to reuse everything that already works and only patch the gaps) or extend `Grammar` directly and reimplement the whole dialect from scratch — both are ordinary PHP inheritance, `GrammarFactory` doesn't care which.

> Two grammars can't share one driver key. Registering `'pgsql'` twice (two `extend()` calls, or an `extend()` plus a config entry) — the **last** `extend()` call wins for that method (`static::$extensions[$driver] = $grammar` is a plain array write), and any `extend()` registration wins over config regardless of order.

---

## Gotchas

- **`wrapValue()` is your injection boundary.** It's called on every bare identifier interpolated into SQL. Always double the quote character on escape — don't strip it, don't reject it, double it — the same guarantee `MySqlGrammar`, `SqliteGrammar`, and `PostgresGrammar` all provide.
- **All six abstract methods must be implemented**, or your class won't compile: `wrapValue()`, `random()`, `typeMap()`, `compileAlter()`, `compileTableExists()`, `compileColumnExists()`. Everything else is optional to override.
- **Register before the first `Connection` is built.** Grammar resolution happens once, eagerly, in `Connection::__construct()`. A provider's `register()` (not `boot()`, and never lazily inside a controller) is the safe place.
- **An incomplete `typeMap()` doesn't fail loudly.** A portable type your map doesn't cover falls back to `strtoupper($column->type)` — silently wrong rather than an exception. Cross-check your map against every column type your migrations actually call.
- **Don't half-implement `compileAlter()`.** If a capability isn't supported yet, throw a clear exception (as `PostgresGrammar` does for column `change()`) rather than silently emitting SQL that doesn't do what the migration asked for.
- **`GrammarFactory::flushExtensions()`** clears all `extend()` registrations — useful between test cases that each register a different grammar for the same driver key, but don't call it in application code.

---

## Related

- [Database Drivers & Grammars](../database/drivers) — the full built-in grammar reference, connection configuration, and driver resolution mechanics.
- [Migrations and Seeds](../database/migrations) — the `Schema`/`Blueprint` DDL layer that calls into a grammar's schema methods.
- [Models](../database/models) — the Active Record layer built on top of `Connection`.
