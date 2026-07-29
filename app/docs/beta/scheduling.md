# Task Scheduling In Atom

## Introduction

Most applications need work to run on a clock — purging soft-deleted rows nightly, sending reminder emails hourly, draining the job queue every couple of minutes. Rather than scattering a `crontab` entry per task across your servers, Atom lets you define all of your scheduled commands in one place, in PHP, and drive them with a **single** cron entry.

You register tasks against the `Scheduler`, each with a frequency. A once-a-minute cron entry runs `schedule:run`, which evaluates every registered task's cron expression against the current time and executes only the ones that are due.

---

## Where Tasks Are Defined

Scheduled tasks live in your application's console kernel — a class extending `Eyika\Atom\Framework\Foundation\ConsoleKernel` — inside its `schedule()` method. The base kernel's `schedule()` is empty; your app overrides it:

```php
<?php

namespace App\Console;

use Eyika\Atom\Framework\Foundation\ConsoleKernel;
use Eyika\Atom\Framework\Support\Facade\Scheduler;

class Kernel extends ConsoleKernel
{
    public function schedule(): void
    {
        // Register application cron jobs here.
        Scheduler::command('expired-sessions:delete')->midnight();
        Scheduler::command('queue:work')->everyTwoMinutes();
        Scheduler::command('transactions:reconcile')->hourly();
    }
}
```

When `schedule:run` executes it resolves this kernel, calls its `schedule()` to populate the task list, then runs whatever is due.

---

## Defining a Task

Every task starts with `Scheduler::command()`, which registers the thing to run and returns the scheduler so you can chain a frequency onto it.

```php
Scheduler::command(
    string|callable|QueueInterface $signature,   // command name to run
    array $arguements = [],                       // arguments to pass
    string|null $expression = null                // optional raw cron expression
);
```

- **`$signature`** is normally a registered console command name, e.g. `'meta:generate'`. It may also be a callable or a `QueueInterface` job.
- **`$arguements`** is an associative array of arguments/options passed to the command, where each key looks like `--key=`, `-key=`, `--key`, or `-key` and the value is a string.
- **`$expression`** lets you supply a raw cron expression inline instead of using a frequency helper.

### Attaching arguments

Besides passing them to `command()`, you can attach arguments fluently:

```php
Scheduler::command('report:build')
    ->arguement('--month=', '2026-07')      // one key/value
    ->daily();

Scheduler::command('report:build')
    ->arguements(['--month=' => '2026-07', '--force' => ''])  // many at once
    ->daily();
```

---

## Frequency Helpers

After `command()` you set *when* it runs. The following helpers exist on the `Scheduler` and each sets the task's cron expression:

| Helper | Cron expression | Runs |
| --- | --- | --- |
| `everyMinute()` | `* * * * *` | Every minute |
| `everyTwoMinutes()` | `*/2 * * * *` | Every 2 minutes |
| `everyThreeMinutes()` | `*/3 * * * *` | Every 3 minutes |
| `everyFiveMinutes()` | `*/5 * * * *` | Every 5 minutes |
| `everyTenMinutes()` | `*/10 * * * *` | Every 10 minutes |
| `everyFifteenMinutes()` | `*/15 * * * *` | Every 15 minutes |
| `everyThirtyMinutes()` | `0,30 * * * *` | Every 30 minutes |
| `hourly()` | `@hourly` | Top of every hour |
| `daily()` | `@daily` | Every day at midnight |
| `midnight()` | `@midnight` | Every day at midnight |
| `weekly()` | `@weekly` | Once a week |
| `monthly()` | `@monthly` | Once a month |
| `yearly()` | `@yearly` | Once a year |
| `annually()` | `@yearly` | Alias of `yearly()` |

```php
Scheduler::command('marketplace:sync-verified')->daily();
Scheduler::command('broker:refresh-ctrader')->everyThirtyMinutes();
Scheduler::command('meta:generate')->hourly();
```

> **Important — these helpers take no arguments.** Unlike Laravel, `daily()`, `hourly()`, etc. accept **no** time parameter. A call like `daily('03:30')` does **not** schedule 3:30 AM — the argument is ignored and the task still runs at midnight (`@daily`). To run at a specific time you must pass a raw cron expression (see below).

### Custom times with a raw cron expression

For any schedule the helpers don't cover — a specific time of day, a specific day of month — pass a standard 5-field cron expression as the third argument to `command()`:

```php
// 05:00 every day
Scheduler::command('subscriptions:renew', [], '0 5 * * *');

// 06:00 on the 7th of each month
Scheduler::command('prop:credit-royalties', [], '0 6 7 * *');

// 08:00 every day
Scheduler::command('billing:remind-expiring', [], '0 8 * * *');
```

Expressions are validated with `dragonmantank/cron-expression`; an invalid expression throws when the task is evaluated. The same library also accepts the `@hourly`/`@daily`/`@midnight`/`@weekly`/`@monthly`/`@yearly` macros the helpers use.

---

## Running the Scheduler

`schedule:run` is the command that evaluates the task list and dispatches due tasks:

```bash
php artisan schedule:run
```

When it runs it:

1. Takes the current time.
2. Calls your kernel's `schedule()` to register all tasks.
3. For each task with an expression, checks `isDue(now)` and, if due, runs the command with its arguments.
4. Logs a line per command it runs and a summary of how many ran (or "No scheduled commands are ready to run.").

A task with no expression attached is skipped.

### The cron entry

You do **not** put every task in cron — you put `schedule:run` in cron, once, to fire every minute. Each minute it wakes up and runs only the tasks that are due:

```cron
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

That single line is the only crontab entry your application needs; all scheduling detail lives in your kernel's `schedule()` method.

---

## The `Scheduler` Facade

Tasks are registered through the `Scheduler` facade
(`Eyika\Atom\Framework\Support\Facade\Scheduler`), which resolves the underlying
`Eyika\Atom\Framework\Foundation\Console\Scheduler` instance bound as `scheduler`
in the console kernel. Its documented static entry point is:

```php
Scheduler::command(string $signature, array $arguements = [], string|null $expression = null);
```

The fluent methods (`everyFiveMinutes()`, `daily()`, `arguement()`, …) are called
on the instance that `command()` returns, exactly as shown throughout this page.

---

## A Complete Example

From a real application kernel:

```php
public function schedule(): void
{
    // Nightly maintenance
    Scheduler::command('expired-sessions:delete')->midnight();
    Scheduler::command('soft-deleted:purge', [], '30 3 * * *');   // 03:30
    Scheduler::command('meta:generate', [], '0 2 * * *');         // 02:00

    // Drain the job queue frequently
    Scheduler::command('queue:work')->everyTwoMinutes();

    // Hourly reconciliation
    Scheduler::command('transactions:reconcile')->hourly();
    Scheduler::command('withdrawals:reconcile')->hourly();

    // Periodic refresh
    Scheduler::command('broker:refresh-ctrader')->everyThirtyMinutes();

    // Specific-time billing crons (raw expressions for exact times)
    Scheduler::command('subscriptions:renew', [], '0 5 * * *');   // 05:00
    Scheduler::command('billing:remind-expiring', [], '0 8 * * *'); // 08:00
}
```

Notice that anything needing a precise time uses a raw cron expression, while the coarse "every N minutes / hourly / midnight" cases use the fluent helpers.

---

## Best Practices

1. **One cron entry.** Only `schedule:run` goes in the system crontab; everything else is PHP.
2. **Use raw expressions for exact times.** The `daily()`/`hourly()` helpers ignore any time argument — reach for a `'m h * * *'` expression when the minute or hour matters.
3. **Keep scheduled commands fast and idempotent.** `schedule:run` runs them in-process, one after another; a slow command delays the ones after it.
4. **Schedule `queue:work`.** Because the queue worker drains and exits, running it on a short interval (e.g. `everyTwoMinutes()`) is the intended way to process background jobs. See the [Queues & Jobs](queue) docs.
5. **Validate custom expressions.** An invalid cron string throws — test new expressions before deploying.

---

## Conclusion

Atom's scheduler centralizes all your recurring work in a single `schedule()` method: register commands with `Scheduler::command()`, pick a cadence with a frequency helper or a raw cron expression, and let one `* * * * *` cron entry running `schedule:run` fire whatever is due. The helper set is deliberately focused on interval- and macro-based cadences; for precise clock times, supply the cron expression directly.
