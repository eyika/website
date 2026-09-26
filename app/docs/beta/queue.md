# Queues & Jobs In Atom

## Introduction

The Atom framework ships a lightweight database-backed job queue so you can defer slow or non-critical work — sending email, processing payment webhooks, reconciling transactions — out of the request/response cycle. A controller dispatches a job and returns immediately; a separate worker process picks the job up later and runs it.

Under the hood the queue is a single table of serialized job payloads. Dispatching writes a row; the worker (`queue:work`) polls that table, reserves the next due job, unserializes it, and calls its `handle()` method. Jobs can be delayed, prioritized, retried (buried), or moved to a failed-jobs table.

This page is usage-focused — how to configure, dispatch, work, and monitor jobs. For the full mechanics of `ShouldQueue`, `PendingDispatch`, and every way a job's lifecycle can go wrong, see [Custom Queued Jobs](extending/jobs).

> **Scope note.** `config/queue.php` looks like a full multi-driver config (`sync`/`database`/`redis`/`beanstalkd`/`sqs`), and it is still **not read anywhere** — there is no `Queue` manager resolving `'default'` to a driver, so `QUEUE_CONNECTION` changes nothing. What the queue does follow is your **database** configuration: it runs on the connection `database.default` names, whatever its driver. `mysql`, `sqlite`, `pgsql` and `sqlsrv` are supported; `redis` and `sqs` are not, and `beanstalkd` exists in the storage engine but nothing wires it up. The rest of this page calls out where the config file diverges from reality.

## Table of Contents

- [Configuration](#configuration)
  - [config/queue.php — Connections and Drivers](#configqueuephp--connections-and-drivers)
  - [What Actually Runs](#what-actually-runs)
- [Defining a Job](#defining-a-job)
  - [Generating a Job Class](#generating-a-job-class)
  - [Make It Worker-Runnable](#make-it-worker-runnable)
- [Dispatching a Job](#dispatching-a-job)
  - [The dispatch() Helper (Recommended)](#the-dispatch-helper-recommended)
  - [The Static ::dispatch() (No-Argument Jobs)](#the-static-dispatch-no-argument-jobs)
  - [The Manual init()->run() Flow](#the-manual-init-run-flow)
  - [Fluent Options — Delay, Priority, Pipeline, Connection](#fluent-options--delay-priority-pipeline-connection)
- [Running the Worker](#running-the-worker)
- [Concurrency and the reservation lease](#concurrency-and-the-reservation-lease)
  - [How a job is claimed](#how-a-job-is-claimed)
  - [The reservation lease](#the-reservation-lease)
  - [The overlap guard is per host](#the-overlap-guard-is-per-host)
- [Retrying & Failing](#retrying--failing)
- [Failed Jobs](#failed-jobs)
- [Best Practices](#best-practices)
- [Conclusion](#conclusion)

---

## Configuration

There is a `config/queue.php`, but it is **not read anywhere in the framework today**. Nothing in `Job_Queue`, `ShouldQueue`, `PendingDispatch`, or `JobRunner` calls `config('queue.*')` — there is no `Queue` facade or manager that resolves `'default'` to a driver. The real dispatch/worker path reuses your existing database configuration instead:

Dispatching and working both go through `QueueConnector`, which builds the connection named by `config('database.default')` exactly the way the ORM builds its own — port, charset and the connection's configured PDO options included.

> **Changed in beta.** The dispatcher and the worker used to assemble their own DSNs, and neither wrote a **port**. A database on anything but 3306 was silently not the one the application was using — and with a second engine running beside an older one, the new credentials reached the old host, which answers *access denied*. The dispatcher also read `DB_*` through `env()` rather than `config()`, so an app that configured its database in `config/database.php` with no `.env` got a DSN of empty strings. Both now read the same configured connection, so they cannot disagree about where the queue lives.

Both sides connect to the same database and use:

- **Table:** `jobs` (the pending-job table).
- **Failed table:** `failed_jobs` (rows that exhausted their attempts).
- **Pipeline:** `default` (the queue name — the framework calls a queue a *pipeline*).

You do not need to create these tables by hand — `Job_Queue` creates `jobs` and `failed_jobs` automatically on first use if they don't already exist, using DDL for your driver.

On MySQL, payloads are stored compressed (`COMPRESS()`/`UNCOMPRESS()`) because `use_compression` defaults to `true`. Other drivers have no such function and store payloads as-is.

> **Do not change `use_compression` on a queue that has rows in it.** A payload written compressed is readable only through `UNCOMPRESS()`, so flipping the flag strands every job already queued. Drain the queue first.

Payloads are HMAC-signed with `app.key` before storage and verified before they are unserialized, so make sure `APP_KEY` is set (`php artisan key:generate`) — signing/verifying throws if it's empty.

### config/queue.php — Connections and Drivers

`config/queue.php` ships with the following shape, Laravel-style:

```php
'default' => env('QUEUE_CONNECTION', 'sync'),

'connections' => [

    'sync' => [
        'driver' => 'sync',
    ],

    'database' => [
        'driver' => 'database',
        'table' => 'jobs',
        'queue' => 'default',
        'retry_after' => 90,
        'after_commit' => false,
    ],

    'beanstalkd' => [
        'driver' => 'beanstalkd',
        'host' => 'localhost',
        'queue' => 'default',
        'retry_after' => 90,
        'block_for' => 0,
        'after_commit' => false,
    ],

    'sqs' => [
        'driver' => 'sqs',
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
        'queue' => env('SQS_QUEUE', 'default'),
        'suffix' => env('SQS_SUFFIX'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'after_commit' => false,
    ],

    'redis' => [
        'driver' => 'redis',
        'connection' => 'default',
        'queue' => env('REDIS_QUEUE', 'default'),
        'retry_after' => 90,
        'block_for' => null,
        'after_commit' => false,
    ],

],

'failed' => [
    'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
    'database' => env('DB_CONNECTION', 'mysql'),
    'table' => 'failed_jobs',
],
```

The file's own comment spells out its status: `Drivers: "sync"` is the only one implemented; `database`, `beanstalkd`, `sqs`, and `redis` are `TODO`. Changing `QUEUE_CONNECTION` to `redis`, `sqs`, `beanstalkd`, or `database` in your `.env` has **no effect** on how jobs run — there is no code that reads `'default'` and switches drivers.

> If you're evaluating Atom's queue for production use today, don't plan around `redis`/`sqs` at all, and don't plan around `beanstalkd` yet — the `Job_Queue` storage class has a `QUEUE_TYPE_BEANSTALKD` code path (see below), but nothing wires it up. The SQL drivers *are* wired up: the queue follows `database.default`.

### What Actually Runs

Independently of `config/queue.php`, the dispatch/worker path is fixed to:

- **Storage engine:** `Job_Queue`, constructed with the driver of your default database connection.
- **Connection:** a `PDO` handle for `config('database.default')`, built by `QueueConnector` through `Connection::makePdo()` — the same DSN, charset and options the ORM uses.
- **Table / failed table:** `jobs` / `failed_jobs`, auto-created if missing.
- **Pipeline:** `default`, unless you call `->onQueue('name')`.
- **Compression:** on by default (`use_compression => true`).

`Job_Queue` takes its SQL dialect from the connection it is handed rather than from the type it was constructed with, so identifier quoting and `COMPRESS()` follow the real driver even if you pass your own handle to `onConnection()`. A `beanstalkd` queue is still buildable by instantiating `Job_Queue` yourself, but that is outside what `ShouldQueue`/`queue:work` do out of the box.

---

## Defining a Job

### Generating a Job Class

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

### Make It Worker-Runnable

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
            // Re-queue for a later retry (see "Retrying & Failing" below).
            $this->bury(30);
        }
    }
}
```

Any state your `handle()` needs (like `$user` above) should be constructor arguments or public/private properties — the whole object is `serialize()`d into the payload, so its properties travel with it to the worker. Keep them small and re-fetchable: store IDs and re-query in `handle()` rather than embedding large hydrated objects (see [Models](database/models)).

> **Note on namespaces/location.** `make:job` writes to `app/Jobs` with the `App\Jobs` namespace. Existing applications in this repo keep their jobs under `app/Console/Jobs` (`App\Console\Jobs`) instead. Either location works as long as the class is autoloadable and implements `QueueInterface`.

For the deeper walkthrough of the `QueueInterface` contract, `SignedPayload` serialization, and every failure mode around authoring a job, see [Custom Queued Jobs](extending/jobs).

---

## Dispatching a Job

There are three ways to get a job onto the queue. They all end up calling the same `ShouldQueue::run()` under the hood, but differ in ergonomics and in whether `run()` is called for you.

### The dispatch() Helper (Recommended)

The global `dispatch()` helper is the recommended entry point, and works for jobs with **or** without constructor arguments:

```php
dispatch(new SendWelcomeEmail($user->toArray()));
```

`dispatch()` wraps your job instance in a `PendingDispatch`, which immediately calls `$job->init()` (wiring the queue connection right away) and then chains any options you add:

```php
dispatch(new SendWelcomeEmail($user->toArray()))
    ->onQueue('emails')
    ->priority(100)
    ->delay(30);
```

> **You don't need to call `->run()`.** `PendingDispatch::__destruct()` calls `run()` automatically, guarded so it only fires once. That means a bare `dispatch(new Job(...));` statement enqueues the job as soon as the statement ends — even with no chaining at all. The only time this matters is if you assign the return value to a variable and hold onto it (e.g. `$pending = dispatch(...)`) — then it won't run until `$pending` goes out of scope, unless you call `$pending->run()` yourself.

### The Static ::dispatch() (No-Argument Jobs)

For jobs whose constructor takes **no arguments**, `ShouldQueue` also exposes a static `dispatch()` that constructs the job and wires the queue in one call:

```php
SendWelcomeEmail::dispatch()->run();
```

> **This is a different method from the global `dispatch()` helper above, and it does *not* auto-run.** Unlike `PendingDispatch`, there is no destructor safety net here — forgetting the trailing `->run()` means nothing is ever enqueued.

### The Manual init()->run() Flow

Both dispatch paths above are convenience wrappers around calling `init()` then `run()` yourself — useful when you want explicit control over when the row is written, or need to swap the connection before dispatching:

```php
$job = new SendWelcomeEmail($user->toArray());
$job->init()->delay(5)->run();
```

`init()` constructs a fresh `Job_Queue` + `PDO` connection from your database env vars and sets the pipeline to `default`. `run()` is what actually signs the job (via `SignedPayload::sign()`) and writes the row to the `jobs` table. Skipping `run()` means nothing is ever enqueued — `init()` alone only prepares the connection.

### Fluent Options — Delay, Priority, Pipeline, Connection

All three dispatch paths above end up chaining the same options (via `ShouldQueue`, or forwarded through `PendingDispatch`) before the row is written:

| Method | Purpose | Default |
| --- | --- | --- |
| `delay(int $seconds)` | Seconds to wait before the job becomes available, and doubles as the retry back-off window when the job is later buried without an explicit delay. | `60` |
| `priority(int $prio)` | Lower number = higher priority (jobs are pulled `ORDER BY priority ASC`). | `1024` |
| `onQueue(string $pipeline)` | Send the job to a named pipeline instead of `default`. | `default` |
| `onConnection(PDO\|SQLite3 $conn)` | Override the PDO/SQLite connection the job uses. | the `database.default` connection |

Order doesn't matter — each just mutates state and returns `self`/`$this`, so `->priority(50)->delay(10)` and `->delay(10)->priority(50)` are equivalent.

```php
$job->init()
    ->onQueue('emails')
    ->priority(100)   // higher priority than the 1024 default
    ->delay(30)       // available in 30 seconds
    ->run();
```

```php
// Splitting work across pipelines so a slow "reports" job never
// blocks time-sensitive "emails" jobs — run separate `queue:work`
// schedules against each if you need independent draining cadences.
dispatch(new BuildMonthlyReport($accountId))->onQueue('reports');
dispatch(new SendWelcomeEmail($user->toArray()))->onQueue('emails');
```

### How a Job Becomes "Queued"

There is no boolean "queued" flag. A job becomes queued the moment `run()` inserts its signed payload into the `jobs` table. From that point the row itself is the queued state — the row's `send_dt` (derived from `delay`), `priority`, `is_reserved`, `is_buried`, and `attempts` columns track its lifecycle. When the worker reserves a job it sets `is_reserved = 1`; when the job calls `delete()` the row is removed; when it calls `bury()` the row is hidden (`is_buried = 1`) until its retry time.

---

## Running the Worker

Process queued jobs with:

```bash
php artisan queue:work
```

`queue:work` runs the `JobRunner`, which:

1. Connects to the `database.default` connection and watches the `default` pipeline.
2. Loops, pulling the next available job with `getNextJobAndReserve()` — which **selects** the highest-priority, due (`send_dt <= now`), not-currently-reserved, not-buried job for the pipeline, then **claims** it with a conditional update and checks that it really got it. Two statements, not one: if another worker claimed the row in between, the claim changes nothing and this worker moves to the next candidate instead of running a job it does not hold.
3. If nothing pending is found, falls back to `getNextBuriedJob()` — buried jobs whose retry time (`time_to_retry_dt`) has arrived.
4. Verifies the signed payload (`SignedPayload::verify()`), and — if the unserialized object `instanceof QueueInterface` — calls `setJob()`, `setQueue()`, then `handle()`.
5. When **neither** call finds a job, what happens depends on the mode. By default the loop **exits** — `queue:work` drains the queue and stops, so you run it on a schedule. With `--daemon` it sleeps and keeps going, which needs a process supervisor rather than a scheduler.

Because the worker exits when the queue is empty, you run it on a schedule rather than as a resident process. The applications in this repo do exactly that via the scheduler (see [Task Scheduling](scheduling)):

```php
Scheduler::command('queue:work')->everyTwoMinutes();
```

`queue:work` takes seven options:

| Option | Effect |
|---|---|
| `--daemon` | Stay resident, sleeping when idle, instead of draining and exiting. Needs a supervisor. |
| `--once` | Run at most one job, then stop. |
| `--max-jobs=N` | Stop after N jobs, so a backlog cannot make one run overrun the next scheduler tick. |
| `--max-time=S` | Stop after S seconds, for the same reason. |
| `--sleep=S` | Seconds to sleep when idle in daemon mode. |
| `--pipeline=NAME` | Work a pipeline other than `default`. |
| `--no-overlap-guard` | Disable the `flock` that stops two workers on one pipeline. **Read the concurrency section before using this.** |

The connection and table are not options: the queue uses the connection `config('database.default')`
names, and the `jobs` / `failed_jobs` tables. There are no `--tries`, `--timeout` or `--connection`
flags — retries are the job's own business (see [Retrying & Failing](#retrying--failing)), and the
reservation timeout comes from `config('queue.retry_after')`.

> If `handle()` throws an uncaught exception, `JobRunner` buries the job (using its `delay` as the
> retry window) and **keeps working** — the failure is isolated to that job so the rest of the batch
> still drains. The buried job comes back when its retry time arrives. A bury that itself fails is
> swallowed too, on the principle that nothing about one bad job should stop the worker.

---

## Concurrency and the reservation lease

This is the part you need before you run a second worker, and it was previously not written down
anywhere.

### How a job is claimed

A worker claims a job in one of two ways, chosen once per connection by asking the server what it
can do:

- **`SELECT … FOR UPDATE SKIP LOCKED`, inside a transaction** — on MySQL 8.0+, MariaDB 10.6+ and
  PostgreSQL. The database picks the winner: each worker locks a different row and steps over rows
  other workers hold, so N workers claim N different jobs.
- **An optimistic compare-and-swap** — everywhere else (MySQL 5.7, MariaDB below 10.6, SQLite). The
  worker selects a candidate, then claims it with a conditional `UPDATE` and checks it really won.
  Correct, but every worker reads the same head row, so N−1 of them lose and re-read.

Both paths order by `priority ASC, id ASC`. Priority wins; `id` breaks the tie, so jobs of equal
priority — which is all of them, since `priority` defaults to `1024` — come out in the order they
were queued.

### The reservation lease

A claim is a **lease, not a lock**. It is written as a timestamp, and once it lapses another worker
may take the job — *even if the first worker is still running it*.

The lease is `config('queue.retry_after')`, defaulting to **60 seconds**. A handler that
legitimately takes longer must extend its own lease:

```php
public function handle()
{
    foreach ($this->batches() as $batch) {
        $this->processSlowly($batch);
        $this->touch();          // push the reservation forward
    }

    $this->delete();
}
```

> Call `touch()` around each slow step, not once at the top. A lease that has already lapsed cannot
> be reclaimed — another worker may already hold the job.

Raise `retry_after` above your slowest job if you would rather not touch, but understand the
trade-off: it is also how long a job stays stuck after a worker dies mid-job.

### The overlap guard is per host

`queue:work` takes a `flock` on `storage/framework/queue-work-<pipeline>.lock`, which stops two
workers on **the same machine** from working one pipeline.

> ⚠️ **A file lock guards nothing between two machines.** Two containers sharing one database each
> take their own local lock and both proceed. That is supported — the SQL claim is what keeps them
> from running the same job — but only on a server with `SKIP LOCKED`. On MySQL 5.7 or MariaDB below
> 10.6 the compare-and-swap path is still correct, just wasteful under contention.

### What is not implemented

- `config/queue.php` is **not read** except for `retry_after`. `QUEUE_CONNECTION` does nothing; the
  queue follows `config('database.default')`.
- `failed_jobs` is never pruned, and there are no `queue:failed` / `queue:retry` / `queue:flush`
  commands. On MySQL the payloads are `COMPRESS()`-ed, so inspect them with
  `SELECT UNCOMPRESS(payload) FROM failed_jobs`, not a plain `SELECT`.

---

## Retrying & Failing

Inside `handle()` you control a job's fate through the `ShouldQueue` helpers:

- **`$this->delete()`** — the job is done; remove it from the queue.
- **`$this->bury(int $delay = null)`** — re-queue the job for a later retry. It is hidden (`is_buried = 1`) and re-signed, and becomes eligible again after `$delay` seconds (falls back to the job's configured `delay` if you don't pass one). Buried jobs are picked up by the worker's `getNextBuriedJob()` pass. Use this for transient failures.
- **`$this->fail()`** — give up: copy the payload into the `failed_jobs` table and delete it from `jobs`. This is terminal — nothing re-queues a failed job automatically.

Each time the worker reserves a job (pending **or** buried) it increments the row's `attempts` counter, so you can implement a max-retry policy by checking attempts and calling `fail()` once you've retried enough:

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

> `delete()`, `bury()`, and `fail()` are declared `private` on `ShouldQueue`. They can only be called from inside methods of your own job class (typically `handle()`), not from outside code holding a job instance.

> Every path through `handle()` should end in `delete()`, `bury()`, or `fail()`. If none of them run, the row stays `is_reserved = 1` and won't be picked up again until its `time_to_retry_dt` lapses on its own — effectively an accidental delay rather than an immediate retry.

---

## Failed Jobs

Jobs that call `$this->fail()` (or that exhaust whatever retry policy you implement in `handle()`) are copied into the `failed_jobs` table and removed from `jobs`. Each row records the `pipeline`, the compressed `payload`, `added_dt`, and the `attempts` count at the time of failure.

There is no built-in `queue:failed` / `queue:retry` command in this version of the framework — inspecting and re-dispatching failed jobs is a manual (or application-level) task: query `failed_jobs` directly, and if you want to retry one, unserialize/re-sign its payload and `run()` it again, or simply reconstruct the original job and dispatch a fresh copy.

```php
use Eyika\Atom\Framework\Support\Database\DB;

// Example: list recent failures for an admin dashboard.
$failures = DB::table('failed_jobs')
    ->where('pipeline', '=', 'default')
    ->orderBy('added_dt', 'DESC')
    ->limit(50)
    ->get();
```

> Because payloads are HMAC-signed (see [Custom Queued Jobs](extending/jobs#how-a-job-is-serialized-signedpayload)) and optionally compressed, don't try to hand-edit or hand-decode the `payload` column — use `Eyika\Atom\Framework\Support\SignedPayload::verify()` to get back the original job object.

---

## Best Practices

1. **Always implement `QueueInterface`.** A job that only uses the `ShouldQueue` trait will be silently skipped by the worker.
2. **Prefer the global `dispatch()` helper.** It works for jobs with or without constructor arguments and enqueues automatically on destruct — no forgotten `->run()`.
3. **Keep payloads small and serializable.** The entire object is serialized; store IDs and re-fetch models in `handle()` rather than embedding large objects. Closures, open PDO handles, and other unserializable state won't round-trip.
4. **Make `handle()` idempotent.** A buried/retried job can run more than once — guard against double side-effects.
5. **Always resolve the job.** End every path in `handle()` with `delete()`, `bury()`, or `fail()`, otherwise the row lingers reserved and re-runs after its reservation lapses.
6. **Schedule `queue:work` frequently.** Since the worker drains and exits, a short scheduler interval (e.g. every two minutes) keeps latency low. See [Task Scheduling](scheduling).
7. **Set `APP_KEY`.** Payload signing/verification requires it; an empty key throws at dispatch and at work time.
8. **Don't rely on `config('queue.*')` to change behavior yet.** `QUEUE_CONNECTION` is not read; the queue follows `config('database.default')`.

---

## Conclusion

Atom's queue is intentionally compact: a signed payload in a `jobs` table on your own database connection, a trait that enqueues it, and a `queue:work` command that drains it. Define a job by implementing `QueueInterface` with a `handle()` method, dispatch it with `dispatch(new Job(...))` (or `Job::dispatch()->run()` / `->init()->run()` for finer control), and let a scheduled `queue:work` do the rest — using `delete()`, `bury()`, and `fail()` to steer each job's outcome. For the full mechanics behind every one of these calls, continue to [Custom Queued Jobs](extending/jobs).