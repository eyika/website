# Queues & Jobs In Atom

## Introduction

The Atom framework ships a lightweight database-backed job queue so you can defer slow or non-critical work — sending email, processing payment webhooks, reconciling transactions — out of the request/response cycle. A controller enqueues a job and returns immediately; a separate worker process picks the job up later and runs it.

Under the hood the queue is a single table of serialized job payloads. Enqueuing writes a row; the worker (`queue:work`) polls that table, reserves the next due job, unserializes it, and calls its `handle()` method. Jobs can be delayed, prioritized, retried (buried), or moved to a failed-jobs table.

> **Scope note.** The storage engine (`Job_Queue`) also has code paths for `sqlite` and `beanstalkd`, but the runtime wiring that dispatches and works jobs (`ShouldQueue` and the `JobRunner`) is hardcoded to **MySQL**. Treat MySQL as the supported path; the other drivers exist at the storage layer only.

---

## Configuration

There is no `config/queue.php`. The queue reuses your existing database configuration:

- The worker (`JobRunner`) reads `config('database.connections.mysql.*')` (driver, host, database, username, password, port, charset).
- The dispatch helper (`ShouldQueue::dispatch()` / `init()`) reads the `DB_DATABASE`, `DB_HOST`, `DB_USERNAME`, and `DB_PASSWORD` env variables directly.

Both connect to the same MySQL database and use:

- **Table:** `jobs` (the pending-job table).
- **Failed table:** `failed_jobs` (rows that exhausted their attempts).
- **Pipeline:** `default` (the queue name — the framework calls a queue a *pipeline*).

You do not need to create these tables by hand — `Job_Queue` creates `jobs` and `failed_jobs` automatically on first use if they don't already exist. Payloads are stored compressed (MySQL `COMPRESS()`/`UNCOMPRESS()`) because `use_compression` defaults to `true`.

Payloads are HMAC-signed with `app.key` before storage and verified before they are unserialized, so make sure `APP_KEY` is set (`php artisan key:generate`).

---

## Defining a Job

### Generating a job class

Use the `make:job` command to scaffold a job:

```bash
php artisan make:job SendWelcomeEmail
```

This writes `app/Jobs/SendWelcomeEmail.php` with the `ShouldQueue` trait and an empty `handle()` method:

```php
<?php

namespace App\Jobs;

use Eyika\Atom\Framework\Foundation\Console\Concerns\ShouldQueue;

class SendWelcomeEmail
{
    use ShouldQueue;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        //
    }
}
```

### Make it worker-runnable

The generated stub is intentionally minimal. For the worker to actually run a job, the class **must implement `QueueInterface`** — the `JobRunner` skips any unserialized payload that is not an instance of it. The `ShouldQueue` trait already supplies every `QueueInterface` method except `handle()`, so you only add the `implements` clause and write your logic:

```php
<?php

namespace App\Jobs;

use Exception;
use Eyika\Atom\Framework\Foundation\Console\Concerns\ShouldQueue;
use Eyika\Atom\Framework\Foundation\Console\Contracts\QueueInterface;

class SendWelcomeEmail implements QueueInterface
{
    use ShouldQueue;

    public function __construct(private array $user)
    {
    }

    /**
     * Execute the job. Runs inside the worker process.
     */
    public function handle()
    {
        try {
            // ... do the work, e.g. send the email ...

            // Job succeeded — remove it from the queue.
            $this->delete();
        } catch (Exception $e) {
            logger()->error('SendWelcomeEmail failed: ' . $e->getMessage());
            // Re-queue for a later retry (see "Retrying & failing" below).
            $this->bury(30);
        }
    }
}
```

Any state your `handle()` needs (like `$user` above) should be constructor arguments or public/private properties — the whole object is serialized into the payload, so its properties travel with it to the worker.

> **Note on namespaces/location.** `make:job` writes to `app/Jobs` with the `App\Jobs` namespace. Existing applications in this repo keep their jobs under `app/Console/Jobs` (`App\Console\Jobs`) instead. Either location works as long as the class is autoloadable and implements `QueueInterface`.

---

## Dispatching a Job

### For jobs with constructor arguments (the common case)

Instantiate the job, call `init()` to attach the queue connection, then `run()` to enqueue it:

```php
$job = new SendWelcomeEmail($user->toArray());
$job->init()->delay(5)->run();
```

`run()` is what actually writes the row to the `jobs` table (it signs `$this` and calls `addJob`). Without `run()` nothing is enqueued.

### For jobs with no constructor arguments

The `ShouldQueue` trait also exposes a static `dispatch()` that constructs the job and wires the queue in one call (it does `new self`, so it only works when the constructor takes no arguments):

```php
SendWelcomeEmail::dispatch()->run();
```

### Fluent options

Both `init()` and `dispatch()` return the job instance, so you can chain the following before `run()`:

| Method | Purpose | Default |
| --- | --- | --- |
| `delay(int $seconds)` | Wait this many seconds before the job becomes available, and the retry back-off window. | `60` |
| `priority(int $prio)` | Lower number = higher priority (jobs are pulled `ORDER BY priority ASC`). | `1024` |
| `onQueue(string $pipeline)` | Send the job to a named pipeline instead of `default`. | `default` |
| `onConnection(PDO\|SQLite3 $conn)` | Override the PDO/SQLite connection the job uses. | env-derived MySQL PDO |

```php
$job->init()
    ->onQueue('emails')
    ->priority(100)   // higher priority than the 1024 default
    ->delay(30)       // available in 30 seconds
    ->run();
```

### How `ShouldQueue` marks a job as queued

There is no boolean "queued" flag. A job becomes queued the moment `run()` inserts its signed payload into the `jobs` table. From that point the row itself is the queued state — the row's `send_dt` (derived from `delay`), `priority`, `is_reserved`, `is_buried`, and `attempts` columns track its lifecycle. When the worker reserves a job it sets `is_reserved = 1`; when the job calls `delete()` the row is removed; when it calls `bury()` the row is hidden (`is_buried = 1`) until its retry time.

---

## Running the Worker

Process queued jobs with:

```bash
php artisan queue:work
```

`queue:work` runs the `JobRunner`, which:

1. Connects to MySQL, watches the `default` pipeline.
2. Loops, pulling the next available job with `getNextJobAndReserve()` (respecting delay, priority, reservations, and retry windows).
3. Verifies the signed payload, unserializes it, and — if it implements `QueueInterface` — calls `setJob()`, `setQueue()`, then `handle()`.
4. When no pending or buried job remains, the loop **exits** (the command is not a long-lived daemon — it drains the queue and stops).

Because the worker exits when the queue is empty, you run it on a schedule rather than as a resident daemon. The applications in this repo do exactly that via the scheduler (see the [Task Scheduling](scheduling.md) docs):

```php
Scheduler::command('queue:work')->everyTwoMinutes();
```

> `queue:work` takes no options — the connection, table, and pipeline are all fixed (MySQL / `jobs` / `default`). This is a deliberately small surface; there are no `--queue`, `--tries`, or `--timeout` flags.

---

## Retrying & Failing

Inside `handle()` you control a job's fate through the `ShouldQueue` helpers:

- **`$this->delete()`** — the job is done; remove it from the queue.
- **`$this->bury(int $delay = null)`** — re-queue the job for a later retry. It is hidden (`is_buried = 1`) and re-signed, and becomes eligible again after `$delay` seconds (falls back to the job's configured `delay`). Buried jobs are picked up by the worker's `getNextBuriedJob()` pass. Use this for transient failures.
- **`$this->fail()`** — give up: copy the payload into the `failed_jobs` table and delete it from `jobs`.

Each time the worker reserves a job it increments the row's `attempts` counter, so you can implement a max-retry policy by checking attempts and calling `fail()` once you've retried enough:

```php
public function handle()
{
    if (($this->job['attempts'] ?? 0) >= 3) {
        return $this->fail();     // exhausted retries → failed_jobs
    }

    if (!$this->doWork()) {
        return $this->bury(10);   // transient failure → retry in 10s
    }

    $this->delete();              // success
}
```

The reserved job's metadata (`id`, `attempts`, `payload`) is available on `$this->job`, which the worker sets before calling `handle()`.

---

## Best Practices

1. **Always implement `QueueInterface`.** A job that only uses the `ShouldQueue` trait will be silently skipped by the worker.
2. **Keep payloads small and serializable.** The entire object is serialized; store IDs and re-fetch models in `handle()` rather than embedding large objects.
3. **Make `handle()` idempotent.** A buried/retried job can run more than once — guard against double side-effects.
4. **Always resolve the job.** End every path in `handle()` with `delete()`, `bury()`, or `fail()`, otherwise the row lingers reserved and re-runs after its reservation lapses.
5. **Schedule `queue:work` frequently.** Since the worker drains and exits, a short scheduler interval (e.g. every two minutes) keeps latency low.
6. **Set `APP_KEY`.** Payload signing/verification requires it; an empty key throws at dispatch and at work time.

---

## Conclusion

Atom's queue is intentionally compact: a signed payload in a MySQL `jobs` table, a trait that enqueues it, and a `queue:work` command that drains it. Define a job by implementing `QueueInterface` with a `handle()` method, dispatch it with `->init()->run()` (or `Job::dispatch()->run()`), and let a scheduled `queue:work` do the rest — using `delete()`, `bury()`, and `fail()` to steer each job's outcome.
