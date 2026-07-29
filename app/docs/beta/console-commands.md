# Console Commands (Artisan)

**Atom** ships a command-line tool named **artisan** that lives at the root of every application. It gives you scaffolding generators, database migrations, cache/route compilation, a development server, a queue worker, and the task scheduler — all from the terminal. This page documents every command the framework ships, its exact signature, what it does, and its real options.

## Running Artisan

The `artisan` file in your project root is the console entry point. Invoke it with PHP:

```bash
php artisan <command> [arguments] [--options]
```

The binary loads Composer's autoloader, boots the application from `bootstrap/app.php`, resolves the `ConsoleKernel`, and dispatches the first argument as the command name — everything after it is passed to the command as arguments. Running `php artisan` with no command dispatches the default command name `random-quote`; if your application registers a command by that name (see [Registering your own commands](#9-registering-your-own-commands)) it runs, otherwise artisan reports `Command 'random-quote' not found`.

```bash
php artisan migrate
php artisan make:model Post
php artisan serve --port=8080
```

### How arguments and options are parsed

Atom keeps its own lightweight parser (it does not use Symfony Console). The rules are:

- **Positional arguments** are read by index — the first non-option token is `argument(0)`, the second is `argument(1)`, and so on. Most generators take the class name as `argument(0)`.
- **Options** are declared in a command's `$signature` inside braces and take these forms:
  - `{--name=}` — a value option, passed as `--name=value`.
  - `{--name}` — a boolean flag, passed as `--name` (its value becomes `true` when present).
- Short flags (`-p=8080`) are supported by the same parser.

Every command exposes a `$signature` (its name plus its option list) and, usually, a `$description`. The examples below quote the real signatures verbatim.

---

## 1. **Generators (`make:*`)**

The `make:*` family scaffolds files from stubs into conventional directories. Most take a single class-name argument and write `app/…/<Name>.php`. If the target file already exists, the command aborts with an error rather than overwriting it.

> Many generators (`make:cast`, `make:event`, `make:job`, `make:listener`, `make:mail`, `make:middleware`, `make:observer`, `make:policy`, `make:provider`, `make:request`, `make:resource`, `make:rule`) write the class name **verbatim** — pass the exact PascalCase name you want. `make:factory` and `make:seeder` additionally require the name to be PascalCase and will reject other casings.

### `make:model`

```bash
php artisan make:model Post
```

Generates `app/Models/Post.php` extending `App\Models\Model`, with soft-deletes enabled, a snake-case pluralised `$table`, an `id`/`created_at`/`updated_at`/`deleted_at` scaffold, and `fillable`/`guarded` constant arrays to fill in. The name is normalised to PascalCase for the class and pluralised snake_case for the table.

### `make:controller`

```bash
php artisan make:controller PostController
php artisan make:controller PostController --api
```

Generates a full CRUD controller (`show`, `list`, `create`, `update`, `delete`) in `app/Http/Controllers/`. A second positional argument selects the flavour:

- `--web` *(default)* — writes to `app/Http/Controllers/`.
- `--api` — writes to `app/Http/Controllers/Api/` under the `App\Http\Controllers\Api` namespace.

The generated actions already wire up `Validator`, `JsonResponse`, and the matching `App\Models\*` model.

### `make:migration`

```bash
php artisan make:migration create_posts_table
```

Writes a timestamped migration to `database/migrations/` (e.g. `2026_07_28_120000_create_posts_table.php`). The stub returns an anonymous class extending the framework `Migration` with `up()`/`down()` methods and a `Schema::create()` block using `Blueprint`. The name is snake-cased for both the filename and the table name inside the stub.

### `make:seeder`

```bash
php artisan make:seeder PostSeeder
php artisan make:seeder PostSeeder --table=posts
```

Writes `database/seeds/PostSeeder.php` extending the framework `Seeder`, with a `run()` method that calls `$this->insert($table, $data)`.

- `--table=` — the target table name. When omitted, it is derived by stripping `_seeder` from the class name and pluralising.

The name **must** be PascalCase.

### `make:factory`

```bash
php artisan make:factory PostFactory
php artisan make:factory PostFactory --table=posts
```

Writes `database/factories/PostFactory.php` extending `Factory`, with a `definition()` method returning default attributes and a `getTable()` method. The stub includes commented examples for states, after-hooks, sequences, and relationships.

- `--table=` — the target table name. When omitted, it is derived by stripping `_factory` from the class name and pluralising.

The name **must** be PascalCase. See [Model Factories](database/factories) for the runtime API.

### `make:command`

```bash
php artisan make:command SyncQuotes
```

Writes `app/Console/Commands/SyncQuotes.php` extending `Command`, pre-filled with a `$signature`, `$description`, and a `handle(): bool` method. Edit the `$signature` to your own command name (e.g. `app:sync-quotes`) — see [Registering your own commands](#9-registering-your-own-commands) below.

### `make:middleware`

```bash
php artisan make:middleware EnsureTokenIsValid
```

Writes `app/Http/Middlewares/EnsureTokenIsValid.php` implementing `MiddlewareInterface` with a `handle(Request $request, Closure $next): BaseResponse` method that calls `$next($request)`. See [Middleware](middleware).

### `make:request`

```bash
php artisan make:request StorePostRequest
```

Writes `app/Http/Requests/StorePostRequest.php` — a scaffold-only class exposing a `rules(): array` method. This is a plain container for validation rules; wire it into a controller manually (Atom has no automatic form-request resolution). See [Validation](validation) for the `Validator` API these rules feed.

### `make:resource`

```bash
php artisan make:resource PostResource
```

Writes `app/Http/Resources/PostResource.php` — a scaffold-only class that takes the resource in its constructor and exposes `toArray(): array`. There is no resource-collection or transformation runtime behind it; instantiate it yourself and call `toArray()` when serialising a response.

### `make:rule`

```bash
php artisan make:rule Uppercase
```

Writes `app/Rules/Uppercase.php` with `passes(string $attribute, mixed $value): bool` and `message(): string`. Custom rule objects are consumed by the `Validator`. See [Validation](validation).

### `make:cast`

```bash
php artisan make:cast Money
```

Writes `app/Casts/Money.php` with `get(mixed $value)` and `set(mixed $value)` methods for transforming an attribute when reading from / writing to a model.

### `make:event`

```bash
php artisan make:event OrderShipped
```

Writes `app/Events/OrderShipped.php` — an empty event class with a constructor to hold the event's payload.

### `make:listener`

```bash
php artisan make:listener SendShipmentNotification
```

Writes `app/Listeners/SendShipmentNotification.php` with a `handle($event): void` method.

### `make:observer`

```bash
php artisan make:observer PostObserver
```

Writes `app/Observers/PostObserver.php` with the model lifecycle hooks (`creating`, `created`, `updating`, `updated`, `deleting`, `deleted`). Register it on a model with `YourModel::observe(PostObserver::class)`; a `before` hook returning `false` aborts the write.

### `make:policy`

```bash
php artisan make:policy PostPolicy
```

Writes `app/Policies/PostPolicy.php` with an example `view($user, $model): bool` ability. **This is scaffold-only** — Atom does not ship a `Gate`/policy enforcement runtime, so there is no `authorize()` layer that auto-invokes these methods. Call your policy methods directly from controllers/middleware to make authorization decisions.

### `make:provider`

```bash
php artisan make:provider PaymentServiceProvider
```

Writes `app/Providers/PaymentServiceProvider.php` extending `ServiceProvider` with `register()` and `boot()` methods. Add it to the `providers` array in `config/app.php` to load it. See [Configuration](configuration#service-providers).

### `make:mail`

```bash
php artisan make:mail WelcomeEmail
```

Writes `app/Mail/WelcomeEmail.php` with a `build(): string` method that returns the rendered message body. See [Mail](mail) for composing and sending.

### `make:job`

```bash
php artisan make:job ProcessPayment
```

Writes `app/Jobs/ProcessPayment.php` using the `ShouldQueue` trait, with a `handle(): void` method. See [Queues & Jobs](queue) for dispatching and working jobs, and the [Queue worker](#6-queue) below.

> **Also available:** `make:migrations` and `make:seed` are Phinx-backed variants that shell out to the bundled `atom_phinx` tool (they prepend `create` / `seed:create` respectively). The native `make:migration` and `make:seeder` above are the ones that integrate with the `migrate`/`db:seed` commands documented next — prefer those.

---

## 2. **Migrations**

Migrations are tracked in a `migrations` table (created automatically) and grouped into batches so they can be rolled back together.

### `migrate`

```bash
php artisan migrate
php artisan migrate --seed
php artisan migrate --path=database/migrations/2026_07_28_120000_create_posts_table.php
```

Signature: `migrate {--seed} {--path=} {--basepath=} {--pretend} {--force}`

Runs every pending migration from `database/migrations` (plus any package migration directories registered via `ServiceProvider::loadMigrationsFrom()`), calling each migration's `up()` and recording it in the next batch. Already-migrated files are skipped.

- `--seed` — run `db:seed` after the migrations complete.
- `--path=` — run a single migration file at the given path instead of the whole directory.
- `--basepath=` — override the directory migrations are gathered from.
- `--pretend`, `--force` — **declared but not yet implemented** (no-ops today).

### `migrate:status`

```bash
php artisan migrate:status
```

Signature: `migrate:status {--database}`

Prints a table of every file in `database/migrations` alongside whether it has been migrated (✔️ / ❌).

### `migrate:rollback`

```bash
php artisan migrate:rollback
php artisan migrate:rollback --step=1
php artisan migrate:rollback --batch=3
```

Signature: `migrate:rollback {--step=} {--batch=} {--pretend}`

Rolls back migrations by calling their `down()` and removing the records.

- `--step=` — limit how many migrations are rolled back (most recent first). When omitted, all matching migrations are rolled back.
- `--batch=` — roll back only the migrations recorded in the given batch number.
- `--pretend` — **declared but not yet implemented**.

### `migrate:reset`

```bash
php artisan migrate:reset
```

Signature: `migrate:reset {--database}`

Rolls back **all** migrations, batch by batch, until the database is back to its pre-migration state.

### `migrate:refresh`

```bash
php artisan migrate:refresh
php artisan migrate:refresh --seed
```

Signature: `migrate:refresh {--seed} {--database} {--step=}`

Rolls the migrations back and then re-runs them. `--seed` re-seeds afterwards.

### `migrate:fresh`

```bash
php artisan migrate:fresh
php artisan migrate:fresh --seed
```

Signature: `migrate:fresh {--seed} {--database}`

**Drops every table** in the database (foreign-key checks are toggled off during the drop) and then runs all migrations from scratch. `--seed` re-seeds afterwards. Use this over `migrate:refresh` when you want a genuinely clean schema.

> The `--database` flag is accepted by the migration commands but they operate on the default connection; there is no per-connection switching behind it yet.

---

## 3. **Database Seeding**

### `db:seed`

```bash
php artisan db:seed
php artisan db:seed --class=PostSeeder
php artisan db:seed --path=database/seeds
```

Signature: `db:seed {--path=} {--class=} {--force=}`

Runs the `DatabaseSeeder`, which resolves and executes your seeders.

- `--class=` — run a single seeder class instead of all of them.
- `--path=` — the directory to load seeders from.
- `--force=` — force seeding (e.g. in production).

Generate seeders with [`make:seeder`](#makeseeder).

---

## 4. **Cache & Optimization**

These commands compile hot paths into cache files for production and remove them again.

### `config:cache`

```bash
php artisan config:cache
```

Compiles every file in `config/` into a single cached PHP file, so later boots load the merged configuration in one `require` instead of globbing and re-reading each file (and re-evaluating `env()`). The command always rebuilds from source: it clears any stale cache first, then recompiles.

### `config:clear`

```bash
php artisan config:clear
```

Removes the compiled configuration cache file. The next boot re-reads `config/` and `.env` normally.

### `route:cache`

```bash
php artisan route:cache
```

Compiles the `routes/web.php` and `routes/api.php` files into fast per-file route caches so the router loads a pre-built table in one `require` instead of re-registering routes each request. A route file that contains **closure routes cannot be cached** — that file is skipped (and any stale cache for it is removed) and a warning tells you which closures to convert to controller actions.

### `route:clear`

```bash
php artisan route:clear
```

Removes the compiled `web` and `api` route cache files, so the next boot requires the route source files again.

### `package:discover`

```bash
php artisan package:discover
```

Rebuilds the cached package manifest from Composer's `installed.json`, so auto-discovered package service providers and facade aliases load without re-parsing Composer metadata on each request. Run it after `composer install`/`update` (it is normally wired into Composer's scripts). It prints the discovered packages and how many providers each contributes. See [Configuration → Package Auto-Discovery](configuration#package-auto-discovery).

---

## 5. **Application**

### `key:generate`

```bash
php artisan key:generate
```

Generates a random 32-byte application key (stored as `base64:…`) and writes it to `APP_KEY` in your `.env` file — replacing the existing value if present, or appending it otherwise.

### `serve`

```bash
php artisan serve
php artisan serve --host=127.0.0.1 --port=8080
php artisan serve --port=8080 --timeout=120
```

Starts PHP's built-in development server pointed at `public/index.php`. Options are passed as `key=value` tokens:

- `--host=` / `-a=` — the bind address (default `0.0.0.0`).
- `--port=` / `-p=` — the port (default `80`).
- `--timeout=` / `-t=` — sets `max_execution_time` for the server process.

The command inherits your terminal's stdout/stderr so output streams live (including on Windows).

### `test`

```bash
php artisan test
php artisan test --filter=UserTest
```

Runs the bundled PHPUnit (`vendor/bin/phpunit`) against the `tests` directory. Any extra arguments (e.g. `--filter=…`) are forwarded straight to PHPUnit.

### `vendor:publish`

```bash
php artisan vendor:publish
php artisan vendor:publish --tag=config
php artisan vendor:publish --provider="Vendor\Pkg\PkgServiceProvider" --force
```

Signature: `vendor:publish {--tag=} {--provider=} {--force}`

Copies assets and configuration that packages have registered as publishable (via their service providers) into your application.

- `--tag=` — publish only the resources under a given tag.
- `--provider=` — publish only the resources registered by a specific provider.
- `--force` — overwrite files that already exist.

---

## 6. **Queue**

### `queue:work`

```bash
php artisan queue:work
```

Starts the job runner. It connects to the `jobs` table (MySQL-backed) on the `default` pipeline, then processes pending jobs — and any buried (delayed/retried) jobs — one after another, deserialising each signed payload and calling its `handle()`. When there is nothing left to process it exits.

> This is a **drain-and-exit** worker, not a long-running daemon loop. To keep processing continuously, run it under a supervisor or on a schedule.

Jobs are defined with the `ShouldQueue` trait (scaffold one with [`make:job`](#makejob)) and dispatched from your code:

```php
use App\Jobs\ProcessPayment;

ProcessPayment::dispatch()->delay(60)->onQueue('default')->run();
```

See [Queues & Jobs](queue) for the full dispatch chain (`delay()`, `priority()`, `onQueue()`, `run()`) and the in-job controls a `handle()` uses to manage retries (`bury()`, `fail()`, `delete()`).

---

## 7. **Scheduling**

### `schedule:run`

```bash
php artisan schedule:run
```

Evaluates your scheduled tasks and runs the ones whose cron expression is due at the current minute. This command is meant to be triggered every minute by the system cron:

```
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

Define scheduled tasks in your application's console kernel `schedule()` method (or wherever you resolve the `Scheduler`), using the fluent builder:

```php
use Eyika\Atom\Framework\Support\Facade\Scheduler;

Scheduler::command('app:sync-quotes')->everyFiveMinutes();
Scheduler::command('db:seed', ['--class=PostSeeder'])->daily();
```

The scheduler supports frequency helpers including `everyMinute()`, `everyTwoMinutes()`, `everyThreeMinutes()`, `everyFiveMinutes()`, `everyTenMinutes()`, `everyFifteenMinutes()`, `everyThirtyMinutes()`, `hourly()`, `daily()`, `midnight()`, `weekly()`, `monthly()`, `yearly()`/`annually()`. You can also attach arguments with `arguement($key, $value)` / `arguements([...])`. Under the hood each helper sets a cron expression that `schedule:run` checks with a real cron parser. See [Task Scheduling](scheduling).

---

## 8. **Storage**

These commands manage the symlinks declared in `config/filesystems.php` under the `links` key (a map of *link path → target directory*).

### `storage:link`

```bash
php artisan storage:link
```

Creates each symbolic link declared in `filesystems.links` — typically linking `public/storage` to `storage/app/public` so uploaded files are web-accessible. Missing target directories are created; if a link path already exists the command aborts.

### `storage:unlink`

```bash
php artisan storage:unlink
```

Removes the symlinks declared in `filesystems.links` (deleting the linked directory entries). Use it before re-running `storage:link` if a link needs to be recreated.

See [Filesystem & Storage](filesystem) for the `Storage` facade and disk configuration.

---

## 9. **Registering your own commands**

Atom discovers commands from three places when the console boots (via the `ConsoleServiceProvider`):

1. **Framework commands** — everything under the framework's `Foundation/Console/Commands` directory (all of the above).
2. **Application commands** — every class in `app/Console/Commands`. Generate one with `make:command`; its class `$signature` becomes the command name automatically:

   ```php
   namespace App\Console\Commands;

   use Eyika\Atom\Framework\Foundation\Console\Command;

   class SyncQuotes extends Command
   {
       public string $signature = 'app:sync-quotes';
       public string $description = 'Pull the latest quotes';

       public function handle(): bool
       {
           $this->info('Syncing…');
           return true;
       }
   }
   ```

   Inside `handle()` you have `$this->argument($index)`, `$this->option($name)`, `$this->info()/error()/warn()`, `$this->table($headers, $rows)`, and `$this->call($otherCommand)`.

3. **Package commands** — commands contributed by installed packages through `ServiceProvider::commands()`.

You can also register lightweight **closure commands** in `routes/console.php` using the `Artisan` facade:

```php
use Eyika\Atom\Framework\Foundation\Console\Artisan;
use Eyika\Atom\Framework\Support\Inspiring;

Artisan::command('random-quote', function () {
    $this->info(Inspiring::quote());
})->purpose('Display an inspiring quote');
```

> Naming the closure command `random-quote` makes it the default that runs when you invoke `php artisan` with no arguments. Command output methods are `$this->info()/error()/warn()/warning()/notice()/debug()` (there is no `comment()`); console output is emitted without a log-line prefix, and inline `<fg=…>`/`<options=…>` formatter tags are translated to ANSI by the console colorizer.

The `Kernel` in `app/Console/Kernel.php` (extending the framework `ConsoleKernel`) is where an application can override command loading and define its `schedule()`.

## What's Next?

- Set up recurring work with [Task Scheduling](scheduling).
- Push background work onto the [Queue](queue).
- Learn how providers wire commands and services in [Configuration](configuration).
