# Changelog

Notable changes to the **Atom** framework (`eyika/atom-framework`). The project is **pre-1.0**,
shipped as the moving `dev-main` branch — so there are no version tags yet; pin `dev-main` and
`composer update eyika/atom-framework` to pick up fixes. This page tracks what changed; the
canonical list lives in [`CHANGELOG.md`](https://github.com/eyika/atomframework/blob/main/CHANGELOG.md)
in the repo.

## Unreleased (beta)

### Security

- **Breaking — application key handling.** `key:generate` writes `APP_KEY=base64:…`, but the
  encrypter used that string verbatim; `openssl_encrypt()` truncates it, so the effective AES-256
  key began with the constant bytes `base64:` and carried roughly 150 bits of entropy instead of
  256. The key is now decoded to raw bytes and its length checked against the cipher, failing
  closed. `key:generate` now uses 32 raw random bytes.

  Old payloads are **rejected** rather than opened with the weak key — there is no fallback, by
  design. **Passwords and JWTs are unaffected** (passwords are hashed, and JWTs are signed with
  `app.key` directly), and remember-me cookies simply prompt a fresh login. If your app encrypts
  columns at rest you must re-encrypt them — **back up, upgrade, then re-encrypt, in that order**.
  Migrating before upgrading is a silent no-op, because the encrypter you would re-encrypt with is
  still the old one. See [Rotating the App Key](advanced/key-rotation).

### Added

- **Hashing** — a first-party password hasher. Atom verified passwords but gave you no way to
  create one, so every app called `password_hash()` itself. `Hash::make()` / `Hash::check()` /
  `Hash::needsRehash()` (plus `bcrypt()`) wrap PHP's password API behind `config/hashing.php` —
  bcrypt by default, argon2i and argon2id available. Options you don't set inherit PHP's defaults
  rather than a value pinned by the framework. Hashing is one-way and independent of `APP_KEY`, so
  key rotation never invalidates stored passwords. See [Hashing](hashing).
- **Route model binding** — `Model::getRouteKeyName()` chooses the column a URL segment binds
  against. It defaults to the primary key, so existing routes are unchanged; return `'slug'` (or any
  other column) to bind a human-readable segment. See
  [Route Model Binding](controllers#route-model-binding).
- **Validation** — wildcard rules for collections of objects: `items.*.name` applies a rule to every
  element, so repeated line items can be validated declaratively instead of by hand in the
  controller. Wildcards nest (`orders.*.lines.*.sku`) and errors are keyed by the concrete path
  (`items.1.name`) so you know which element failed. See [Validation](validation).
- **Scheduler** — `dailyAt('HH:MM')`, `at()`, `hourlyAt(int $minute)`, and `withoutOverlapping()`
  (a flock-based mutex, auto-released if the runner dies). `daily('HH:MM')` now honours the time you
  pass it (previously it always ran at midnight). See [Task Scheduling](scheduling).
- **Queue** — `queue:work` gains flags: `--daemon` (stay resident, sleeping when idle), `--once`,
  `--max-jobs`, `--max-time`, `--sleep`, `--pipeline`, and `--no-overlap-guard`. A per-pipeline lock
  stops overlapping workers, and a throwing job is buried for retry rather than aborting the whole
  batch. Periodic drain-and-exit is still the default. See [Queues & Jobs](queue).
- **Database** — pluggable SQL grammars via `GrammarFactory::extend()` and
  `config('database.grammars')`, plus SQLite support (including `PRAGMA foreign_keys` per connection,
  honouring `foreign_key_constraints`). See [Drivers & Grammars](database/drivers) and
  [Custom Database Grammars](extending/database-grammars).
- **Auth** — provider drivers now resolve each guard provider's **own** model
  (`auth.providers.<provider>.model`, falling back to the global `auth.user.model`), so multiple
  guards/providers with different user classes work. See [Custom Auth Guards](extending/auth-guards).
- **Testing** — the application namespace is resolved independent of test-mode, so
  `DatabaseTestCase` works for standard apps whose code lives in `app/`. See [Testing](advanced/testing).
- **Migrations** — `migrate --pretend` and `migrate:rollback --pretend` now do a real dry run: they
  print the ordered list of migrations that would run / roll back and make **no** database changes
  (the flag previously did nothing and silently ran the real operation). See [Migrations](database/migrations).

### Changed

- **Models — `guarded` no longer hides columns from your own code.** It is an *output* filter, but
  it was also removed from the SELECT list, so a guarded column was never loaded and the model's
  property was simply `null` — code reading, say, `created_at` off a plain `get()` silently got
  nothing. That protected nothing extra, since `toArray()` already guards on output and is what the
  JSON response path calls. Exposure is unchanged; only hydration is fixed. **If a model lists a
  column in `fillable` that doesn't exist in its table** and relied on `guarded` to keep it out of
  the query, reads will now fail with `Unknown column` — correct the `fillable` list.
- **Query builder** — a read that matches **nothing** now returns an empty `Collection` rather than
  `false`, so the documented "multi-result reads return a Collection" holds for empty results too and
  `count()` on an empty `get()` no longer raises a `TypeError`. A genuine query failure still
  returns `false`. **Check any `if (!$rows)`** used to mean "nothing found" — an empty `Collection`
  is an object and therefore truthy, so use `count($rows) === 0` or `$rows->isEmpty()`. `foreach`
  and `$rows ?: []` are unaffected.
- **Scheduler** — cron matching now uses `config('app.timezone')` (default UTC) instead of the CLI's
  php.ini timezone, so timed jobs fire at the intended app time.
- **Migrations** — dropped the unimplemented `--force` flag from `migrate` (it had no confirmation
  prompt to bypass).

### Removed

- **Phinx** — the old Phinx integration is gone: the `make:migrations` and `make:seed` commands, plus
  the `atom_phinx` bin. Phinx was no longer a dependency, so both commands were already broken. Use
  the framework's own **`make:migration`** and **`make:seeder`**, which generate `Schema`/`Blueprint`
  migrations and `Seeder` classes for the built-in migration engine. See
  [Migrations](database/migrations).

### Fixed

- **Routing — string route targets** (a route pointing at a plain PHP file rather than a closure or
  controller) never actually worked: the file was resolved against the *framework's* own directory
  inside `vendor/`, where your file can't be. It now resolves against your application base. The
  same target also stopped re-rendering after its first hit in a process — invisible under PHP-FPM,
  but under a persistent worker the page rendered once and then went blank. Resolved paths are
  additionally confined to the application directory, so `../` can't escape.
- **Migrations** — package migration directories (registered with `loadMigrationsFrom()`) were only
  half-honoured: `migrate` ran them, but `migrate:rollback` looked for the file only under the app's
  `database/migrations` and failed with *"Migration file not found"* for a package migration it had
  itself applied, while `migrate:status` never listed them at all. All the migrate commands now
  share one discovery path. See [Migrations](database/migrations).
- **Query builder — `orderBy()`** — successive calls **replaced** each other instead of
  accumulating, so `orderBy('is_default', 'DESC')->orderBy('currency')` sorted by `currency` alone;
  and a comma list applied one direction after the whole list, so `orderBy('a,b', 'DESC')` sorted
  `a` ascending. Terms now accumulate and each column keeps its own direction. Neither case raised
  an error — the rows just came back in the wrong order. Fixed in both the model builder and the
  static `DB` builder.
- **Schema / indexes** — `dropUnique(['col'])` and `dropIndex(['col'])` now work outside MySQL. Two
  things blocked them: index-name resolution used a MySQL-only `INFORMATION_SCHEMA` query (it is now
  delegated to the driver's grammar, using `PRAGMA` on SQLite), and a column-level `->unique()`
  compiled to an inline `UNIQUE` on every driver — which on SQLite becomes an implicit
  `sqlite_autoindex_*` that the engine refuses to drop, making the constraint permanent. On SQLite
  and Postgres a column-level unique is now a named index instead. MySQL DDL is unchanged; on SQLite
  a `CREATE UNIQUE INDEX "unique_<column>"` statement accompanies the table, and uniqueness is
  enforced exactly as before. See [Migrations](database/migrations).
- **Route model binding** — binding previously only worked for an existing **numeric** id, and
  failed silently otherwise. Slug and UUID segments were skipped and reached the controller as raw
  strings (including models whose primary key is a UUID), and a missing row was skipped rather than
  raising — so `ModelNotFoundException` never actually fired and no 404 was produced. Two further
  defects sat on that dead path: the exception's constructor had a required argument after an
  optional one (so the throw raised `ArgumentCountError`), and an app **without** an `app/Models`
  directory got a 500 on any route carrying a parameter. All fixed; see
  [Route Model Binding](controllers#route-model-binding).
- **Console** — `artisan test` and `artisan serve` now work when the project path contains a space.
  They built their subprocess command without quoting, so a path like `C:\Users\Some Name\…` was
  split by the shell and PHP reported `Could not open input file: C:\Users\Some`. Every path and
  argument is now quoted.
- **Models** — a column cast to `'object'` can now be written. Casts run on writes as well as reads,
  and the framework re-encodes the decoded payload just before it reaches the database — but that
  step only handled arrays, and an `'object'` cast decodes to a `stdClass`. Both `create()` and
  `update()` previously failed with *"Object of class stdClass could not be converted to string"*.
  See [Models](database/models).
- **Error handling** — the error handler no longer writes stray `got here now …` debug lines into
  your logs. Atom registers it as PHP's error handler, so those fired on every notice, warning and
  deprecation — and even on `@`-suppressed operations, because the first one ran before the
  `error_reporting()` check. Each one built a logger, read config and appended to `storage/logs`,
  which on PHP 8.4 meant a steady stream of noise. It also made the handler itself fatal on an error
  raised before config could be loaded.
- **Testing** — the integration `TestCase` now restores the previous facade application on teardown.
  Previously it left the global facade app pointing at its own booted container, so a test running
  afterwards (e.g. a DB-only `DatabaseTestCase`) could have `App::make()`/facades — and container
  overrides like `$this->app->instance($fake)` — resolving from the wrong app (an order-dependent
  bug that passed in isolation). See [Testing](advanced/testing).
- **Migrations** — MySQL tables are now created `utf8mb4` (`ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci`) instead of inheriting the server default charset — on a latin1
  server a multi-byte character (e.g. `σ`) previously died with `1366 Incorrect string value`.
  Override via `database.connections.mysql.{engine,charset,collation}`.
- **Migrations** — `migrate:status` now correctly marks already-run migrations as migrated (it was
  comparing a name against table rows, so everything showed as not-migrated).
- **Migrations** — a fresh `php artisan migrate` no longer fails; the bootstrap migrations-table
  builder emitted invalid MySQL 8 DDL.
- **Auth** — provider drivers no longer authenticate a second provider against the wrong (global)
  model.
- **Query builder** — SELECT column identifiers are quoted, so a column named with a SQL reserved
  word (e.g. `values`) no longer produces invalid SQL.
- **Query builder** — instance `update()`/`delete()` on a row read from a multi-row `get()` now
  targets **that row by its primary key**, instead of reusing the source query's `WHERE` (which
  previously wrote every matching row). Bulk `Model::where(...)->update()/delete()` still affects all
  matching rows.

---

> Building on Atom? If a doc is wrong or a method is missing, that's a bug worth reporting — the
> fixes above mostly came from real apps hitting real edges.
