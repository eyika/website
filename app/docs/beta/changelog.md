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
  columns at rest, re-encrypt them before upgrading — see
  [Rotating the App Key](advanced/key-rotation).

### Added

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
