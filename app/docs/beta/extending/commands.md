# Custom Console Commands

This page goes deeper on **authoring** console commands than the [Console Commands (Artisan)](../console-commands) usage guide — the mechanics of the `Command` base class, exactly how `$signature` is parsed into a name and an option list, how arguments/options are read back inside `handle()`, the output helpers available to you, and every place a command actually gets wired into `php artisan`. Read the usage guide first if you haven't already; this page assumes you know what `php artisan` is for and focuses on how to build your own command correctly.

## Table of Contents

- [The Base Class](#the-base-class)
- [Scaffolding With make:command](#scaffolding-with-makecommand)
- [$signature and $description](#signature-and-description)
- [handle() and the Exit Code](#handle-and-the-exit-code)
- [Reading Arguments](#reading-arguments)
- [Reading Options](#reading-options)
- [Output Helpers](#output-helpers)
  - [info(), error(), warn(), and friends](#info-error-warn-and-friends)
  - [table()](#table)
- [Calling Another Command](#calling-another-command)
- [Registering Your Command](#registering-your-command)
  - [Auto-Discovery From app/Console/Commands](#auto-discovery-from-appconsolecommands)
  - [Constructor Dependencies](#constructor-dependencies)
  - [Closure Commands in routes/console.php](#closure-commands-in-routesconsolephp)
  - [Commands Contributed by a Package](#commands-contributed-by-a-package)
- [Scheduling a Custom Command](#scheduling-a-custom-command)
- [Gotchas](#gotchas)
- [Conclusion](#conclusion)

---

## The Base Class

Every command extends `Eyika\Atom\Framework\Foundation\Console\Command`:

```php
abstract class Command implements ShouldLogMessages
{
    use LogsMessages;

    public string $description = '';
    public string $signature = '';

    public function handle(): bool
    {
        throw new NotImplementedException('method is not implemented');
    }

    public function arguments(): array { /* ... */ }
    public function argument(int $index): null|string { /* ... */ }
    public function options(): array { /* ... */ }
    public function option($name, $default = null): null|string { /* ... */ }

    protected function call(string $name, array $arguments = [], bool $requireConsoleRoute = false) { /* ... */ }
    protected function table(array $headers, array $rows) { /* ... */ }
}
```

`Command` itself is `abstract`, but `handle()` isn't declared `abstract` — it has a default body that throws `NotImplementedException`. That means the compiler won't stop you from forgetting to override it; you'll only find out at runtime, the first time the command actually executes. Always give your subclass its own `handle(): bool`.

The `LogsMessages` trait (via the `ShouldLogMessages` contract) is what supplies `info()`, `error()`, `warning()`/`warn()`, `notice()`, `debug()`, `critical()`, and `emergency()` — see [Output Helpers](#output-helpers) below.

## Scaffolding With make:command

```bash
php artisan make:command SyncQuotes
```

Writes `app/Console/Commands/SyncQuotes.php`:

```php
<?php

namespace App\Console\Commands;

use Eyika\Atom\Framework\Foundation\Console\Command;

class SyncQuotes extends Command
{
    public string $signature = 'app:command-name';
    public string $description = 'Command description';

    public function handle(): bool
    {
        $this->info('SyncQuotes executed.');

        return true;
    }
}
```

Like every `make:*` generator, it aborts with an error rather than overwriting an existing file at that path. Edit `$signature` and `$description` to fit your command, then fill in `handle()` — the two things you almost always change first:

```php
public string $signature = 'app:sync-quotes';
public string $description = 'Pull the latest quotes from the upstream feed';
```

## $signature and $description

`$signature` is a single space-separated string: **the command's name, followed by its option declarations.**

```php
public string $signature = 'app:sync-quotes {--force} {--limit=}';
```

When a command is discovered, the framework does `explode(' ', $signature)` and shifts the first token off as the registration key — that first token is exactly what you type after `php artisan`. Everything after it is a list of option specs, each wrapped in braces:

- `{--name=}` — a **value** option, passed on the command line as `--name=value`.
- `{--name}` — a **boolean flag**, passed as `--name` with no `=`.

Short single-dash forms (`{-p=}`, `{-p}`) work the same way. `$description` is a plain sentence shown by whatever help/listing surface reads it (and by `ServiceProvider::commands()`/`loadCommands()` when they register the command) — it doesn't affect parsing.

> Declaring a command's options in `$signature` is good, self-documenting practice, but nothing in the parser currently *rejects* an option that isn't declared there — `parseOptions()` has a `// TODO` where that check would live. In practice, any `--whatever=value` token on the command line ends up in `option()` whether or not you listed it in `$signature`. Declare your options anyway; it's the only place a reader learns what a command accepts, and it's what feeds `setAllowedOptions()` when the kernel wires the command up.

## handle() and the Exit Code

`handle()` returns `bool`, and that return value **is** the process exit code — not just a "did it work" flag you check yourself. The console kernel does:

```php
public function terminate($inputs = []): int
{
    return intval(!$this->status);
}
```

where `$this->status` is whatever `handle()` returned. So `return true;` exits `0` (success, the shell sees no error), and `return false;` exits `1` (failure). If your command can fail, make sure every failing path actually returns `false` — logging an error with `$this->error()` and then falling through to `return true;` at the bottom of the method will still report success to anything scripting around `php artisan`.

The built-in commands lean on this by catching their own exceptions and translating the exception's code into the boolean:

```php
public function handle(): bool
{
    try {
        // ...
        return true;
    } catch (BaseConsoleException $e) {
        $this->error($e->getMessage());
        return !(bool)($e->getCode());
    }
}
```

`BaseConsoleException` defaults its `$code` to `1`, so an uncaught failure there returns `false` (exit `1`) as you'd expect.

## Reading Arguments

Everything typed after the command name on the CLI — positional values **and** option tokens — lands in one flat, ordered list, accessible as `$this->arguments()` (the whole array) or by position with `$this->argument($index)`:

```php
public function handle(): bool
{
    $name = $this->argument(0); // null if nothing was passed at that index

    $this->info("Hello, {$name}!");

    return true;
}
```

```bash
php artisan app:greet World
```

`argument($index)` returns `null` if nothing was supplied at that index — there's no "required argument" declaration or validation built in; check for `null` yourself and fail loudly (`return false;` after an `$this->error(...)`) if the argument is mandatory.

> **The array is raw — option flags are not filtered out of it.** `php artisan make:controller PostController --api` gives you `argument(0) === 'PostController'` and `argument(1) === '--api'` (the literal string, unparsed) in the same array `option('api')` reads from separately. This is exactly how the built-in `make:controller` reads its second argument. If you mix positional arguments after option flags, their indices shift — put your positional arguments first and options after, the way every example in this guide does.

## Reading Options

Declared or not (see the callout above), any `--name=value` / `--name` / `-name=value` / `-name` token on the command line is parsed into `$this->options()` — a name → value map — and read back with `option()`:

```php
public string $signature = 'app:sync-quotes {--force} {--limit=}';

public function handle(): bool
{
    $limit = (int) $this->option('limit', '100'); // value option, with a default
    $force = $this->option('force');               // boolean flag

    if ($force) {
        $this->warn('Forcing a full resync.');
    }

    $this->info("Syncing up to {$limit} quotes...");

    return true;
}
```

```bash
php artisan app:sync-quotes --limit=50
php artisan app:sync-quotes --force
```

> **A boolean flag doesn't come back as `true`.** `option()` is declared `: null|string`, but a flag with no `=` is stored internally as the PHP boolean `true`. Since the file isn't in `strict_types` mode, PHP coerces that `true` to satisfy the `string` half of the return type — which for a bool means the literal string `"1"`. So `option('force')` on `--force` returns `"1"`, not `true`. A plain truthy check (`if ($this->option('force'))`) works fine either way, but `=== true` never will. Missing options come back as whatever `$default` you passed (`null` if you didn't pass one) — that part behaves exactly as you'd expect.

## Output Helpers

### info(), error(), warn(), and friends

`Command` gets `info()`, `error()`, `warning()` (aliased as `warn()`), `notice()`, `debug()`, `critical()`, and `emergency()` from the `LogsMessages` trait — all with the same signature:

```php
public function info(string $message, array $context = [], $to_log_file = false): void
```

By default (`$to_log_file = false`) the message is written straight to `STDOUT`/`STDERR` as user-facing console output — no `[datetime] channel.LEVEL:` log prefix, just the message. Pass `$to_log_file = true` to route it through the application's normal logger instead (the same underlying channel as the global `info()`/`error()`/... helpers — see [Logging](../logging)), on top of printing nothing to the console.

```php
$this->info('Sync starting...');
$this->warn('Rate limit is close — backing off.');
$this->error('Upstream feed returned an error.');

$this->error('Sync failed, see log for details', ['feed' => $feedName], to_log_file: true);
```

Console output supports a small set of inline formatter tags that get translated to ANSI color codes (and stripped entirely otherwise, so unrecognized tags never leak to the terminal as literal text):

```php
$this->info('<fg=green>Sync complete.</>');
$this->info('<fg=white;bg=red;options=bold>CRITICAL</> upstream feed is down');
```

`warning()`/`warn()` and anything more severe (`error()`, `critical()`, `emergency()`) also get the **whole line** colored automatically by level, regardless of inline tags — you don't need `<fg=red>` on an `error()` call for it to show up red.

### table()

`table(array $headers, array $rows)` renders a simple ASCII table and prints it via `info()`:

```php
$this->table(
    ['Migration', 'Migrated?'],
    [
        ['2026_07_28_120000_create_posts_table', '✔️ Yes'],
        ['2026_07_28_130500_create_tags_table', '❌ No'],
    ]
);
```

```
+----------------------------------------+-----------+
| Migration                              | Migrated? |
+----------------------------------------+-----------+
| 2026_07_28_120000_create_posts_table   | ✔️ Yes    |
| 2026_07_28_130500_create_tags_table    | ❌ No     |
+----------------------------------------+-----------+
```

Column widths are computed from the longest header/cell in each column; there's no pagination or truncation, so very wide tables just wrap in the terminal.

## Calling Another Command

`call(string $name, array $arguments = [], bool $requireConsoleRoute = false)` (protected) runs another registered command by its signature name, from inside `handle()`:

```php
if ($this->option('seed')) {
    $this->call('db:seed');
}
```

This is exactly how the built-in `migrate` command triggers seeding after `--seed` is passed. Under the hood it forwards to `Artisan::call()`, which resolves the target through the same console kernel registry your own command was looked up in.

> `call()` doesn't return anything — its own method body has no `return`, so the sub-command's success/failure `bool` is discarded. If you need to know whether the thing you called succeeded, you can't get that through `call()`; instantiate and run the target command class directly instead.

`$requireConsoleRoute` defaults to `false` here (unlike the kernel's own `run()`, which defaults it to `true`), so `call()` does **not** re-`require` `routes/console.php` — safe, since every command (framework, project, and package) is already loaded into the registry by the time any `handle()` runs.

## Registering Your Command

### Auto-Discovery From app/Console/Commands

You don't register application commands by hand. During boot, `ConsoleServiceProvider::boot()` calls:

```php
$kernel->loadCommands();         // framework's own Commands directory
$kernel->loadProjectCommands();  // app/Console/Commands
```

`loadProjectCommands()` recursively scans every `.php` file under `app/Console/Commands` (including subdirectories — the framework's own commands are organized the same way, e.g. `Foundation/Console/Commands/Db/Migrate.php`), resolves each class, reads its `$signature`, and registers it under the **first word** of that signature — not the class name:

```php
$args = explode(' ', $command_obj->signature);
$signature = array_shift($args) ?: strtolower($class_name);
$this->register($signature, $command_obj, $args, $command_obj->description);
```

A `SyncQuotes` class with `$signature = 'app:sync-quotes {--force}'` is invoked as `php artisan app:sync-quotes`, not `php artisan syncquotes` or `php artisan SyncQuotes`. (If you leave `$signature` empty, it falls back to the lowercased class name — worth avoiding by just always setting `$signature`.)

Because discovery is recursive and file-path based, a command's namespace has to match its location under `app/Console/Commands` the normal PSR-4 way — put `App\Console\Commands\Reports\GenerateReport` at `app/Console/Commands/Reports/GenerateReport.php`.

### Constructor Dependencies

Commands discovered this way (and the framework's own) go through the same reflection-based dependency resolver used elsewhere in the framework, so a constructor can type-hint a service and have it resolved from the container:

```php
class SyncQuotes extends Command
{
    public string $signature = 'app:sync-quotes';

    public function __construct(private QuoteFeed $feed)
    {
    }

    public function handle(): bool
    {
        $this->feed->sync();
        return true;
    }
}
```

> This only applies to commands loaded via `loadCommands()`/`loadProjectCommands()`. Commands registered through `ServiceProvider::commands()` (see below) are instantiated with a bare `new $commandClass()` — no constructor injection — so keep a package-contributed command's constructor either empty or resolve dependencies from inside `handle()` (`app()->make(...)`) instead.

### Closure Commands in routes/console.php

For something too small to warrant its own class, register a closure directly against the `Artisan` facade in `routes/console.php`:

```php
use Eyika\Atom\Framework\Foundation\Console\Artisan;

Artisan::command('app:ping', function () {
    $this->info('pong');
})->purpose('Health-check the console');
```

Any arguments passed on the command line are splatted straight into the closure's parameters:

```php
Artisan::command('app:greet', function ($name) {
    $this->info("Hello, {$name}!");
});
```

```bash
php artisan app:greet World
```

> `routes/console.php` is `require`d from inside `ConsoleKernel::run()`, so a closure defined there automatically captures `$this` bound to the **`ConsoleKernel` instance**, not a `Command`. That gives you `$this->info()`/`error()`/`warning()`/`notice()`/`debug()`/`critical()`/`emergency()` (`LogsMessages`, same trait `Command` uses) plus `$this->comment()` (a `ConsoleKernel`-only alias for `info()`) — but **not** `$this->argument()`, `$this->option()`, `$this->table()`, or `$this->call()`, none of which exist on `ConsoleKernel`. Reach for a real `Command` class (`make:command`) the moment you need arguments/options beyond plain function parameters, or any of the `Command`-only helpers.

Naming a closure command `random-quote` makes it the default `php artisan` runs with no arguments at all (that's the fallback name baked into the `artisan` entry point) — see [Console Commands](../console-commands#running-artisan).

### Commands Contributed by a Package

A package (or a provider inside your own app) can contribute commands without them living under `app/Console/Commands`, via `ServiceProvider::commands()`:

```php
public function boot(): void
{
    $this->commands([
        \App\Providers\Reports\Console\GenerateReportCommand::class,
    ]);
}
```

`ConsoleKernel::loadPackageCommands()` reads every class registered this way (across all booted providers), instantiates it, and registers it under its own `$signature` — the same as auto-discovery, just sourced from provider registrations instead of a directory scan. See [Service Providers](service-providers).

## Scheduling a Custom Command

Once your command has a `$signature`, you can schedule it the same way as any built-in command — by name, from your Kernel's `schedule()`:

```php
use Eyika\Atom\Framework\Support\Facade\Scheduler;

Scheduler::command('app:sync-quotes', ['--force'])->everyFiveMinutes();
```

See [Task Scheduling](../scheduling) for the full frequency-helper API and how `schedule:run` picks up due tasks.

## Gotchas

- **`handle()`'s default body throws.** `Command` is `abstract`, but `handle()` itself isn't — forgetting to override it fails at runtime (`NotImplementedException`), not at compile time.
- **The `bool` you return from `handle()` becomes the shell exit code.** `true` → `0`, `false` → `1` (`ConsoleKernel::terminate()` = `intval(!$status)`). A failure path that logs `$this->error(...)` and then falls through to `return true;` still reports success to anything checking `$?`.
- **`option()`'s declared `null|string` return type doesn't mean booleans go away.** A bare `--flag` is stored as PHP `true` internally and gets coerced to the string `"1"` on the way out (non-strict-types file). Use a truthy check, not `=== true`.
- **`$this->arguments()`/`argument($index)` is the raw, unfiltered token list** — option flags occupy their own index right alongside positional values, in the order they were typed. Put positional arguments before options in your usage examples, the same way every built-in command does.
- **Declaring options in `$signature` is documentation, not enforcement (yet).** `parseOptions()` has a `// TODO` where "reject an undeclared option" would go — right now, any `--anything=value` on the line ends up in `option()`.
- **`$this->call()` discards the callee's return value.** You can't tell from the call site whether the command you invoked succeeded or failed.
- **An empty `$signature` silently falls back to the lowercased class name** as the registration key — always set `$signature` explicitly rather than relying on that fallback.
- **Closures in `routes/console.php` bind `$this` to the `ConsoleKernel`, not a `Command`.** You get the `LogsMessages` methods and `comment()`, but not `argument()`/`option()`/`table()`/`call()` — those only exist on `Command`.
- **Package-contributed commands (`ServiceProvider::commands()`) skip constructor injection.** They're built with a bare `new`, unlike commands discovered from `app/Console/Commands`, which go through the container's dependency resolver.
- **`make:command` (like every `make:*` generator) refuses to overwrite an existing file** at the target path — delete or rename first if you need to regenerate.

## Conclusion

Authoring a command in Atom is one class (`extends Command`), two properties (`$signature`, `$description`), and one method (`handle(): bool`) — read input with `argument()`/`option()`, write output with `info()`/`error()`/`warn()`/`table()`, and return the `bool` that becomes your process's exit code. Drop the class under `app/Console/Commands` and it's discovered automatically by name (the first word of `$signature`), no separate registration step required. For the full catalogue of commands the framework ships out of the box, see [Console Commands (Artisan)](../console-commands); for wiring commands from an installable package, see [Service Providers](service-providers); for running your command on a schedule instead of by hand, see [Task Scheduling](../scheduling).
