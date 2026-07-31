# Changelog

Notable changes to the **Atom** framework (`eyika/atom-framework`). The project is **pre-1.0**,
shipped as the moving `dev-main` branch — so there are no version tags yet; pin `dev-main` and
`composer update eyika/atom-framework` to pick up fixes. This page tracks what changed; the
canonical list lives in [`CHANGELOG.md`](https://github.com/eyika/atomframework/blob/main/CHANGELOG.md)
in the repo.

## Unreleased (beta)

### Added

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

### Changed

- **Scheduler** — cron matching now uses `config('app.timezone')` (default UTC) instead of the CLI's
  php.ini timezone, so timed jobs fire at the intended app time.

### Fixed

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
