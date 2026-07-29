# Database Drivers & Grammars

Atom's database layer talks to the underlying engine through PDO, but every piece of SQL that differs between engines — how identifiers are quoted, how you ask for a random row, how `INSERT` is spelled, how a transaction opens, how `ALTER TABLE` is compiled — is isolated behind a **Grammar** class. `Connection` and the `Schema`/`Blueprint` DDL layer stay engine-agnostic and simply ask the grammar for the dialect-specific fragment they need. This page covers the three built-in grammars, how `config('database')` connections are shaped, and how to register your own grammar for a driver the framework doesn't ship.

---

## Table of Contents

1. [Supported Drivers](#supported-drivers)
2. [Configuring Connections](#configuring-connections)
3. [Driver Resolution](#driver-resolution)
4. [The Grammar Layer](#the-grammar-layer)
5. [MySQL — The Default Grammar](#mysql--the-default-grammar)
6. [SQLite — The Testing Grammar](#sqlite--the-testing-grammar)
7. [PostgreSQL — Foundation Grammar](#postgresql--foundation-grammar)
8. [Registering a Custom Grammar](#registering-a-custom-grammar)
9. [Resolution Order](#resolution-order)
10. [Gotchas & Known Limitations](#gotchas--known-limitations)

---

## Supported Drivers

Three grammars ship with the framework, matched against `config('database.default')`:

| `default` value                  | Grammar class    | Status                                  |
|-----------------------------------|-------------------|------------------------------------------|
| `mysql`, `mariadb`                | `MySqlGrammar`    | **Default.** Full support.               |
| `sqlite`                          | `SqliteGrammar`   | Full support. Primarily used for tests.  |
| `pgsql`, `postgres`, `postgresql` | `PostgresGrammar` | Foundation only — see [below](#postgresql--foundation-grammar). |

Anything else raises an `InvalidArgumentException` telling you to register a grammar for it (see [Registering a Custom Grammar](#registering-a-custom-grammar)).

---

## Configuring Connections

The database configuration lives in `config/database.php`. Two keys matter to the driver/grammar layer:

- **`default`** — the driver key to use. Falls back to `env('DB_CONNECTION', 'mysql')` in the framework's stub config, and to the literal `'mysql'` in `Connection` itself if `default` is missing entirely — **MySQL is the default in both places**.
- **`connections`** — a map of `driver key => connection settings`, keyed by the *same* string as `default`.

```php
// config/database.php
return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_DATABASE', 'atom'),
            'username'  => env('DB_USERNAME', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            // Opt-in persistent PDO handles (off by default — see the gotcha below).
            'persistent' => false,
        ],

        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => __DIR__ . '/../database/database.sqlite', // or ':memory:' for tests
        ],

        'pgsql' => [
            'driver'   => 'pgsql',
            'host'     => env('DB_HOST', '127.0.0.1'),
            'database' => env('DB_DATABASE', 'forge'),
            'username' => env('DB_USERNAME', 'forge'),
            'password' => env('DB_PASSWORD', ''),
        ],
    ],
];
```

`Connection::getDsn()` builds the PDO DSN from `connections[$driver]` per engine:

```php
'mysql'  => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
'sqlite' => "sqlite:{$database}",
'pgsql'  => "pgsql:host={$host};dbname={$database};",
'sqlsrv' => "sqlsrv:Server={$host};Database={$database}",
```

`host` defaults to `127.0.0.1`, `port` to `3306`, and `charset` to `utf8mb4` when omitted. Username/password are read separately (`connections[$driver]['username']` / `['password']`) and passed to `new PDO(...)`.

> **`sqlsrv` gotcha:** the DSN switch has a case for `sqlsrv`, but there is no `SqlServerGrammar` — `GrammarFactory::make('sqlsrv')` would throw unless you [register one](#registering-a-custom-grammar). The DSN entry exists ahead of the grammar.

### PDO options

Every connection is opened with:

```php
PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
PDO::ATTR_EMULATE_PREPARES   => false,
```

Persistent PDO handles are **opt-in**, never default — set `connections.<driver>.persistent = true` to enable `PDO::ATTR_PERSISTENT`. A persistent handle can carry transaction/temp-table/session state between requests, so only enable it for deployments where that's safe.

---

## Driver Resolution

`Connection`'s constructor resolves the grammar **eagerly**, before any query runs and before `connect()` opens a PDO handle — because SQL is assembled (e.g. `condition()`, `values()`, `increment()`) as soon as the connection object exists, not lazily at execution time:

```php
public function __construct(array $config)
{
    $this->config = $config;
    $this->driver = $config['default'] ?? 'mysql';
    $this->grammar = GrammarFactory::make($this->driver);
    self::$activeGrammar = $this->grammar;
}
```

This means: whatever registers a custom grammar (an `extend()` call or a `database.grammars` config entry) **must be in place before the first `Connection` is constructed** — i.e. during a service provider's `register()`, not lazily inside a controller after a query has already run.

`self::$activeGrammar` backs two static helpers used internally (and safe to use from your own code) for escaping a bare identifier without a `Connection` instance in hand:

```php
use Eyika\Atom\Framework\Support\Database\Connection;

Connection::quoteIdent('name');        // -> `name`   (mysql)  or  "name"  (sqlite/pgsql)
Connection::quoteQualified('users.id'); // -> `users`.`id`  or  "users"."id"
```

They always reflect whichever `Connection` was constructed most recently; with a single connection per request this is simply "the current driver's grammar."

---

## The Grammar Layer

`Grammar` (`Eyika\Atom\Framework\Support\Database\Grammars\Grammar`) is an abstract base class. A concrete grammar owns every decision that differs between engines:

**Identifiers**
- `wrapValue(string $value): string` *(abstract)* — quote one bare identifier segment and double any embedded quote character. This is the SQL-injection defence for every column/table name interpolated into SQL (`where()` columns, `ORDER BY`/`JOIN` targets, …) — it must never let a crafted identifier break out of the quoting.
- `wrap(string $value)` — handles a dotted identifier (`table.col` → `` `table`.`col` ``); `*` and a trailing `.*` pass through unquoted.
- `wrapTable(string $table)` — kept distinct from `wrap()` so a grammar can add a schema prefix if needed.

**Dialect functions**
- `random(): string` *(abstract)* — the `ORDER BY` expression for a random row (used by `Connection::random()`).
- `now(): string` — "current timestamp" usable in a `WHERE`/`VALUES` expression. Default `'now()'`.
- `currentTimestamp(): string` — the value expression for setting a column to now in an INSERT/UPDATE. Default `'current_timestamp'`.

**INSERT**
- `supportsInsertSetSyntax(): bool` — does this dialect support MySQL's `INSERT ... SET col = val` form? Default `false`; only MySQL returns `true`. Everything else compiles the standard `INSERT INTO t (cols) VALUES (...)` form via `compileInsert()`.
- `insertKeyword(bool $ignore): string` — the leading `INSERT` keyword plus the dialect's duplicate-skipping modifier.
- `compileIf(string $condition, string $then, string $else): string` — a conditional expression (used by `Connection::toggle()`); MySQL emits `IF(...)`, everything else emits `CASE WHEN ... END`.

**Transactions & locking**
- `compileBeginTransaction(): string` — MySQL/Postgres use `START TRANSACTION`; SQLite requires `BEGIN`.
- `compileForUpdate(): string` — the pessimistic row-lock suffix for a `lock: true` read. MySQL/Postgres emit `' FOR UPDATE'`; SQLite has no row-level locks (the transaction itself serializes) so it's an empty string.

**Schema / DDL** (backs the [`Schema`/`Blueprint` migration API](migrations))
- `typeMap(): array` *(abstract)* — portable column type (`'string'`, `'bigInteger'`, `'json'`, …) → this dialect's base SQL type.
- `indexesInline(): bool` — are secondary indexes declared inline inside `CREATE TABLE` (MySQL: `true`) or as separate `CREATE INDEX` statements (SQLite/Postgres: `false`)?
- `compileColumn()`, `autoIncrementSql()`, `compileModifiers()`, `compileColumnExtras()` — assemble one column's DDL clause, including the dialect's auto-increment phrasing (`AUTO_INCREMENT` vs `AUTOINCREMENT` vs `SERIAL`).
- `compileCreate(Blueprint $blueprint): array` — full `CREATE TABLE`, returning one or more statements.
- `compileAlter(Blueprint $blueprint): array` *(abstract)* — the dialect's `ALTER TABLE` strategy (see per-grammar notes below — they differ significantly).
- `compileTableExists()` / `compileColumnExists()` *(abstract)* — catalog queries backing `Schema::hasTable()` / `Schema::columnExists()`.
- `compileForeignKey()`, `compileDropIfExists()` — foreign-key clause and `DROP TABLE IF EXISTS`.

You will rarely call these directly — `Connection` and `Schema`/`Blueprint` call them for you — but they're the surface a [custom grammar](#registering-a-custom-grammar) must implement.

---

## MySQL — The Default Grammar

`MySqlGrammar` reproduces exactly what the DB layer emitted before grammars existed, so a MySQL app is unaffected by the grammar layer's existence:

- Identifiers: backtick-quoted, `` `like_this` ``, with embedded backticks doubled.
- `random()` → `RAND()`.
- `now()` → `now()`.
- `supportsInsertSetSyntax()` → `true` — `insert()` and `insert_update()` compile MySQL's `INSERT ... SET col = val[, ...]` form.
- `insertKeyword($ignore)` → `INSERT` or `INSERT IGNORE`.
- `compileIf()` → `IF(condition, then, else)`.
- `ON DUPLICATE KEY UPDATE` — `Connection::insert_update()` (upsert) only works when `supportsInsertSetSyntax()` is `true`, i.e. **only on MySQL today**. On SQLite/Postgres it throws:

  ```
  RuntimeException: insert_update() (upsert) is not yet implemented for driver [sqlite].
  ```

- Schema extras only MySQL has: native `ENUM(...)` columns, `UNSIGNED`, `CHARACTER SET`/`COLLATE`/`COMMENT` column clauses, `FIRST`/`AFTER <col>` column positioning, `ON UPDATE CURRENT_TIMESTAMP`.
- `compileAlter()` emits **one** `ALTER TABLE` statement with every change (`ADD`, `MODIFY COLUMN`, `DROP COLUMN`, `RENAME COLUMN`, `DROP INDEX`, `DROP PRIMARY KEY`, …) comma-joined.
- Table/column introspection uses `SHOW TABLES LIKE '...'` and `SHOW COLUMNS FROM ... LIKE '...'`.

```php
// This works only because MySqlGrammar::supportsInsertSetSyntax() is true.
DB::table('users')->insert(['email' => 'a@b.com']); // INSERT INTO `users` SET `email` = :email
```

---

## SQLite — The Testing Grammar

`SqliteGrammar` is primarily the engine used for fast, disposable application tests (an in-memory `sqlite::memory:` database), but it's a fully functional dialect:

- Identifiers: double-quoted, `"like_this"` (SQLite also accepts backticks, but double quotes are the portable choice).
- `random()` → `RANDOM()`.
- `now()` / `currentTimestamp()` → `CURRENT_TIMESTAMP`.
- `compileBeginTransaction()` → `BEGIN` (not `START TRANSACTION`).
- `compileForUpdate()` → `''` — no row locks; a `lock: true` read is silently a plain `SELECT`.
- No `INSERT ... SET` — inserts compile through the standard `compileInsert()` column-list form.
- `autoIncrementSql()` → `INTEGER PRIMARY KEY AUTOINCREMENT` (the only column form that gives SQLite a true auto-increment rowid alias).
- `indexesInline()` → `false` — secondary indexes are separate `CREATE INDEX` statements after `CREATE TABLE`.

**`ALTER TABLE` is per-operation and partly a table rebuild.** SQLite natively supports `ADD COLUMN`, `RENAME COLUMN`, and `DROP COLUMN`, plus separate `CREATE`/`DROP INDEX` — those are emitted directly. But SQLite has no native `MODIFY COLUMN`, so a `change()`d column triggers the "12-step" rebuild: create a temp table with the new column definitions, copy every row across (`INSERT INTO tmp SELECT ... FROM table`), drop the old table, rename the temp table into place, then recreate the table's own indexes from `sqlite_master`. This is data-safe but means a column `change()` on SQLite is comparatively expensive — fine for a migration, not something to run casually against a large table.

> Dropping a primary key, and adding a foreign key via `ALTER`, are **not** supported on SQLite (out of scope of the current grammar) — a `dropIndexes` entry of kind `'PRIMARY'` is silently skipped rather than erroring.

Table/column introspection queries `sqlite_master` and `PRAGMA table_info(...)`.

---

## PostgreSQL — Foundation Grammar

`PostgresGrammar` is explicitly marked **foundation only** in its source docblock. The query layer works — double-quote identifiers, `RANDOM()`, `now()`, standard column-list `VALUES` inserts, `CASE WHEN` conditionals — and the basic DDL type map is in place, but several things a production Postgres app would need are **not yet finished**:

- **No upsert.** `insertKeyword($ignore)` always returns plain `INSERT` — Postgres has no insert-level `IGNORE`; duplicate-skipping needs `INSERT ... ON CONFLICT DO NOTHING`, which isn't wired. `$ignore` / `Connection::insert_update()` do not work as expected on this grammar (see the MySQL section's upsert note — it throws for any non-MySQL grammar).
- **No `RETURNING`-based last-insert-id** handling beyond what `PDO::lastInsertId()` gives you.
- **`compileAlter()` is per-operation only**: `ADD COLUMN`, `RENAME COLUMN`, `DROP COLUMN`, plus separate `CREATE`/`DROP INDEX`. A `change()`d column (`ALTER ... TYPE` / `SET NOT NULL`) throws immediately:

  ```
  RuntimeException: Postgres column change() is not implemented yet (foundation grammar).
  ```

- DDL type map is basic (e.g. `json` → `JSONB`, `blob` → `BYTEA`, `decimal` → `NUMERIC`); no native `ENUM` type, no partial indexes.
- `autoIncrementSql()` → `SERIAL PRIMARY KEY` / `BIGSERIAL PRIMARY KEY`.

Table/column introspection uses `information_schema.tables` / `information_schema.columns`.

> Treat Postgres as a solid starting point, not a finished production backend — complete the grammar (upsert, column `change()`, richer type mapping) for your use case before relying on it, or extend it as shown below.

---

## Registering a Custom Grammar

To add a dialect the framework doesn't ship — or to replace a built-in grammar entirely — implement `Grammar` and register it. This mirrors how a custom auth guard is wired in `config/auth.php`: a programmatic registration point plus a config-driven map, checked in that order.

### 1. Programmatic — `GrammarFactory::extend()`

Call this from a service provider's `register()` (or the console/HTTP kernel), so it runs before any `Connection` is constructed:

```php
use Eyika\Atom\Framework\Support\Database\Grammars\GrammarFactory;
use App\Database\Grammars\FirebirdGrammar;

class AppServiceProvider extends ServiceProvider
{
    public function register()
    {
        // Register a grammar for a driver the framework doesn't ship.
        GrammarFactory::extend('firebird', FirebirdGrammar::class);

        // Or override a built-in driver entirely with a closure factory
        // (useful for a Postgres grammar that finishes upsert/change()):
        GrammarFactory::extend('pgsql', fn () => new MyCompletePostgresGrammar());
    }
}
```

`extend()` accepts either a `Grammar` class-string (instantiated with `new $grammar()`) or a zero-arg callable that returns a `Grammar` instance.

### 2. Config — `database.grammars`

Add a `driver => Grammar class-string` map to `config('database')`, keyed the same way as `connections`:

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

    // driver => Grammar class-string
    'grammars' => [
        'firebird' => \App\Database\Grammars\FirebirdGrammar::class,
    ],
];
```

Either way, the class **must** extend `Grammar` — `GrammarFactory` verifies this and throws `InvalidArgumentException` (`"Grammar registered for driver [...] must extend Grammar."`) if it doesn't.

> The config lookup (`fromConfig()`) is guarded: if `config()` isn't available yet, or reading it throws, it's treated as "no config registration" rather than blowing up — so grammar resolution never fails in an isolated unit test where the config subsystem isn't booted.

---

## Resolution Order

`GrammarFactory::make($driver)` resolves in this order, so a custom dialect — or a full replacement for a built-in one — can be plugged in the same way a custom auth guard is:

1. **Programmatic registrations from `extend()`** — a service provider's `register()`/`boot()`, or the console/HTTP kernel.
2. **`config('database.grammars')`** — a `['<driver>' => Grammar::class]` map.
3. **The built-in grammars** — `mysql`/`mariadb` → `MySqlGrammar`, `sqlite` → `SqliteGrammar`, `pgsql`/`postgres`/`postgresql` → `PostgresGrammar`.

If none of the three match, it throws:

```
InvalidArgumentException: No SQL grammar registered for database driver [foo].
Register one with GrammarFactory::extend('foo', YourGrammar::class) or via config('database.grammars').
```

An `extend()` call always wins even over a `database.grammars` entry for the same driver key — useful when you want to force an override regardless of what's in config (e.g. in tests, paired with `GrammarFactory::flushExtensions()` to reset between cases).

---

## Gotchas & Known Limitations

- **`mariadb` has a grammar but no DSN case.** `GrammarFactory` treats `'mariadb'` as an alias for `MySqlGrammar`, but `Connection::getDsn()`'s driver switch only has cases for `'mysql'`, `'sqlite'`, `'pgsql'`, and `'sqlsrv'`. Setting `database.default` to `'mariadb'` resolves the right *grammar* but throws `Unsupported database driver` when `connect()` builds the DSN. Point `default` at `'mysql'` for a MariaDB server (the PDO `mysql` driver talks to MariaDB fine); `'mariadb'` as a distinct grammar key exists for when you register your own DSN handling alongside it.
- **Same gap for `'postgres'` / `'postgresql'`.** Both are grammar-level aliases for `PostgresGrammar`, but the DSN switch only recognizes `'pgsql'`. Use `'pgsql'` as `database.default`.
- **The Postgres DSN doesn't include a port.** `getDsn()`'s `pgsql` case is `"pgsql:host={$host};dbname={$database};"` — no `port={$port}` segment, unlike the `mysql` case. A non-default Postgres port needs a custom DSN (extend `Connection` or the grammar's surrounding wiring) until this is filled in.
- **`insert_update()` (upsert) only works where `supportsInsertSetSyntax()` is true** — today that's MySQL only. Calling it against SQLite or Postgres throws a `RuntimeException` rather than silently emitting wrong SQL.
- **Grammar resolution happens once, at `Connection` construction**, not per-query. Registering a custom grammar (either method) after the app's first `Connection` has already been built has no effect on that connection — do it in a provider's `register()`, not lazily.
- **`compileForUpdate()` is a no-op on SQLite.** Code that assumes `lock: true` serializes concurrent readers works differently there than on MySQL/Postgres — SQLite's transaction semantics provide the serialization instead.

---

## Related

- [Model Query Builder](models) — the Active Record layer built on top of `Connection`.
- [Query Builder](query-builder) — the raw `DB` fluent builder.
- [Migrations and Seeds](migrations) — the `Schema`/`Blueprint` DDL layer that calls into a grammar's schema methods.
