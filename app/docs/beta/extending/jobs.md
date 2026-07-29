# Custom Queued Jobs

This page goes deeper on **authoring and dispatching** queued jobs than the [Queues & Jobs](../queue) usage guide — the exact mechanics of `QueueInterface`, the `ShouldQueue` trait, `PendingDispatch`, the `Job_Queue` storage engine's reservation queries, the `JobRunner` worker loop, and `SignedPayload` serialization. Read the usage guide first if you haven't; this page assumes you know what `dispatch()` and `queue:work` do and focuses on precisely how and why.

## Table of Contents

- [The QueueInterface Contract](#the-queueinterface-contract)
- [The ShouldQueue Trait](#the-shouldqueue-trait)
  - [What init() and Static dispatch() Actually Wire Up](#what-init-and-static-dispatch-actually-wire-up)
  - [Fluent Setters — delay(), priority(), onQueue(), onConnection()](#fluent-setters--delay-priority-onqueue-onconnection)
  - [run() — Signing and Storing the Job](#run--signing-and-storing-the-job)
  - [delete(), bury(), fail() — Private Lifecycle Methods](#delete-bury-fail--private-lifecycle-methods)
- [Dispatch Helper vs Trait: PendingDispatch Internals](#dispatch-helper-vs-trait-pendingdispatch-internals)
  - [The Global dispatch() Helper](#the-global-dispatch-helper)
  - [PendingDispatch, Step by Step](#pendingdispatch-step-by-step)
  - [Static ::dispatch() Has No Destructor Safety Net](#static-dispatch-has-no-destructor-safety-net)
- [How a Job Is Serialized (SignedPayload)](#how-a-job-is-serialized-signedpayload)
- [Inside the Job_Queue Storage Engine](#inside-the-job_queue-storage-engine)
  - [Queue Types](#queue-types)
  - [addJob() — The Insert](#addjob--the-insert)
  - [getNextJobAndReserve() — The Reservation Query](#getnextjobandreserve--the-reservation-query)
  - [getNextBuriedJob() — The Retry Query](#getnextburiedjob--the-retry-query)
  - [deleteJob(), buryJob(), failJob(), kickJob()](#deletejob-buryjob-failjob-kickjob)
  - [Auto-Created Tables](#auto-created-tables)
  - [Compression](#compression)
- [Running a Worker: queue:work / JobRunner](#running-a-worker-queuework--jobrunner)
- [Failed Jobs](#failed-jobs)
- [config('queue.*') — What It Does Not Do](#configqueue--what-it-does-not-do)
- [Gotchas](#gotchas)
- [Conclusion](#conclusion)

---

## The QueueInterface Contract

`Eyika\Atom\Framework\Foundation\Console\Contracts\QueueInterface` is what the worker checks for before it will run anything it pulls off the queue:

```php
interface QueueInterface
{
    public function handle();

    public function setJob(array $job): void;
    public function setQueue(Job_Queue $queue): void;

    public function init(): self;
    public function delay(int $delay): self;
    public function priority(int $prio): self;
    public static function dispatch(): self;
    public function onQueue(string $pipeline_name): self;
    public function onConnection(PDO|SQLite3 $connection): self;
    public function run(): void;
}
```

Every method except `handle()` is already implemented for you by the `ShouldQueue` trait (below). That means a job class only needs two things: `use ShouldQueue;` and `implements QueueInterface`, plus your own `handle()`. `make:job` (`php artisan make:job SendWelcomeEmail`) scaffolds the trait usage but **not** the `implements QueueInterface` clause — you add that yourself, otherwise `JobRunner` silently skips the unserialized object (it checks `instanceof QueueInterface` before calling `handle()`).

```php
<?php

namespace App\Jobs;

use Eyika\Atom\Framework\Foundation\Console\Concerns\ShouldQueue;
use Eyika\Atom\Framework\Foundation\Console\Contracts\QueueInterface;

class SendWelcomeEmail implements QueueInterface
{
    use ShouldQueue;

    public function __construct(private array $user) {}

    public function handle()
    {
        // ... send the email ...
        $this->delete();
    }
}
```

## The ShouldQueue Trait

`Eyika\Atom\Framework\Foundation\Console\Concerns\ShouldQueue` (`src/Foundation/Console/Concerns/ShouldQueue.php`) carries the job's queue state and every fluent setter:

```php
trait ShouldQueue
{
    private $delay = 60;
    private $priority = 1024;
    private array $job;
    private static Job_Queue $queue;

    public function setJob(array $job): void { $this->job = $job; }
    public function setQueue(Job_Queue $queue): void { $this::$queue = $queue; }
    public function getDelay(): int { return $this->delay; }

    public static function dispatch(): self { /* ... */ }
    public function init(): self { /* ... */ }
    public function delay(int $delay): self { /* ... */ }
    public function priority(int $prio): self { /* ... */ }
    public function onQueue(string $pipeline_name): self { /* ... */ }
    public function onConnection(PDO|SQLite3 $connection): self { /* ... */ }

    private function fail() { /* ... */ }
    private function delete() { /* ... */ }
    private function bury(int|null $delay = null) { /* ... */ }

    public function run(): void { /* ... */ }
}
```

`$job` is populated by the worker (`setJob()`) right before `handle()` runs — it's the raw row array (`id`, `attempts`, `payload`) the storage engine reserved, and it's what `delete()`/`bury()`/`fail()` operate on. `$queue` is a **static** property, so it's shared across all instances of the same class within a process — that's fine because a single dispatch/worker call only ever deals with one job at a time.

### What init() and Static dispatch() Actually Wire Up

Both `init()` (instance method) and `dispatch()` (static method) do the identical thing — construct a fresh `Job_Queue` bound to `default` and a brand-new `PDO` MySQL connection built straight from env vars:

```php
public function init(): self
{
    $this::$queue = new Job_Queue('mysql', [
        'mysql' => [
            'table_name' => 'jobs',
            'use_compression' => true
        ]
    ]);
    $db_name = env('DB_DATABASE');
    $db_host = env('DB_HOST');

    $pdo = new PDO("mysql:dbname=$db_name;host=$db_host", env('DB_USERNAME'), env('DB_PASSWORD'));
    $this::$queue->addQueueConnection($pdo);
    $this::$queue->setPipeline('default');
    $this::$queue->selectPipeline('default');

    return $this;
}
```

`dispatch()` is the same body, but `static` — it constructs a *new instance* of the job (`$me = new self;`) and wires the queue onto it, then returns `$me`. It only works for jobs whose constructor takes no required arguments, since it calls `new self` with no arguments:

```php
SendWelcomeEmail::dispatch()->run();
```

There is no separate "sqlite" or "beanstalkd" path here — both `init()` and the static `dispatch()` hardcode `new Job_Queue('mysql', ...)` and a MySQL PDO DSN. See [config('queue.*') — What It Does Not Do](#configqueue--what-it-does-not-do).

### Fluent Setters — delay(), priority(), onQueue(), onConnection()

| Method | Effect |
| --- | --- |
| `delay(int $seconds)` | Sets `$this->delay` (default `60`). Used both as the "not available until" offset when the job is inserted, and — if you don't pass an explicit delay to `bury()` — as the retry back-off window. |
| `priority(int $prio)` | Sets `$this->priority` (default `1024`). Lower number wins (`ORDER BY priority ASC` in the reservation query). |
| `onQueue(string $pipeline)` | Calls `$this::$queue->setPipeline($pipeline)` — changes which pipeline the job is written to / read from. Default is `'default'`, set by `init()`. |
| `onConnection(PDO\|SQLite3 $connection)` | Calls `$this::$queue->addQueueConnection($connection)` — swaps the underlying connection object on the already-constructed `Job_Queue`. |

All four just mutate state and `return $this`/`$this::$queue`, so chaining order doesn't matter:

```php
$job = new SendWelcomeEmail($user->toArray());
$job->init()
    ->onQueue('emails')
    ->priority(100)
    ->delay(30)
    ->run();
```

> **`onConnection()` swaps the connection, not the queue type.** The `Job_Queue` instance created by `init()`/`dispatch()` is always constructed with `queue_type = 'mysql'`, so every SQL path inside `Job_Queue` (table creation, `COMPRESS()`/`UNCOMPRESS()`, `SHOW TABLES LIKE`) stays MySQL-flavored regardless of what object you pass to `onConnection()`. The parameter type is `PDO|SQLite3` because `Job_Queue` itself supports both engines at the storage layer (see [Queue Types](#queue-types)), but `ShouldQueue::onConnection()` doesn't also flip `queue_type` — passing a `SQLite3` handle here will break as soon as `checkAndIfNecessaryCreateJobQueueTable()` runs MySQL-only SQL against it. In practice, only use `onConnection()` to point at a **different MySQL PDO connection**, not a different engine.

### run() — Signing and Storing the Job

```php
public function run(): void
{
    $sclass = \Eyika\Atom\Framework\Support\SignedPayload::sign($this);
    $this::$queue->addJob($sclass, $this->delay, $this->priority, $this->delay);
}
```

`run()` is the only method that actually writes a row. It signs the **whole job object** (`$this`, including every constructor-injected property) and calls `Job_Queue::addJob()` with `$this->delay` used for *both* the `$delay` and `$time_to_retry` parameters. That means whatever `delay()` you set doubles as the retry-back-off window baked into the row at insert time (`time_to_retry_dt`) — see [addJob()](#addjob--the-insert).

Calling `init()` (or the static `dispatch()`) without ever calling `run()` prepares the connection but writes nothing — the row only exists once `run()` executes.

### delete(), bury(), fail() — Private Lifecycle Methods

```php
private function fail()
{
    $this::$queue->failJob($this->job);
    return $this::$queue->deleteJob($this->job);
}

private function delete()
{
    return $this::$queue->deleteJob($this->job);
}

private function bury(int|null $delay = null)
{
    $id = $this->job['id'];
    unset($this->job);
    $sclass = \Eyika\Atom\Framework\Support\SignedPayload::sign($this);
    $this->delay = $delay ?? $this->delay;
    return $this::$queue->buryJob(['payload' => $sclass, 'id' => $id], $this->delay);
}
```

All three are `private` — they can only be called from inside your job class (typically `handle()`), never from external code holding a job instance. Note what `bury()` actually does: it `unset($this->job)` (so the stale job-row metadata isn't captured), then **re-signs the entire job object** (including whatever mutated state your `handle()` left it in) as the new payload, and calls `Job_Queue::buryJob()` with that new payload plus a retry delay (falls back to the job's own `$delay` if you pass `null`).

`fail()` copies the current payload into the `failed_jobs` table (via `failJob()`) and then deletes the row from `jobs` — it does not re-sign anything first, so the failed payload reflects the object's state as of when it was originally enqueued/re-buried, not any in-flight mutation `handle()` made before calling `fail()`.

## Dispatch Helper vs Trait: PendingDispatch Internals

There are three ways to get a job onto the queue — the global `dispatch()` helper, `ShouldQueue::dispatch()`, and the manual `init()->run()` flow. `queue.md` covers the usage-level differences; this section is the exact call sequence.

### The Global dispatch() Helper

Defined in `src/helpers.php`:

```php
if (!function_exists('dispatch')) {
    function dispatch(object $job)
    {
        return new \Eyika\Atom\Framework\Foundation\Console\PendingDispatch($job);
    }
}
```

It takes any object — not necessarily one using `ShouldQueue` — and wraps it in `PendingDispatch`. Everything past this point is guarded with `method_exists()` checks, so `dispatch()` on an object that doesn't have `init()`/`run()`/etc. is a silent no-op rather than a fatal error.

### PendingDispatch, Step by Step

```php
class PendingDispatch
{
    protected object $job;
    protected bool $dispatched = false;

    public function __construct(object $job)
    {
        $this->job = $job;
        if (method_exists($job, 'init')) {
            $job->init();
        }
    }

    public function delay(int $delay): self { /* forwards to $this->job->delay() */ }
    public function onQueue(string $pipeline): self { /* forwards to $this->job->onQueue() */ }
    public function priority(int $prio): self { /* forwards to $this->job->priority() */ }
    public function onConnection($connection): self { /* forwards to $this->job->onConnection() */ }

    public function run(): void
    {
        if ($this->dispatched) return;
        $this->dispatched = true;
        if (method_exists($this->job, 'run')) {
            $this->job->run();
        }
    }

    public function __destruct()
    {
        $this->run();
    }
}
```

Two things matter here:

1. **`init()` runs immediately, inside the constructor.** By the time `dispatch(new SendWelcomeEmail(...))` returns, the job already has its `Job_Queue` + `PDO` connection wired up (default pipeline, default delay/priority) — before you've chained anything.
2. **`run()` fires automatically on destruct, guarded by `$dispatched`.** A bare statement —

   ```php
   dispatch(new SendWelcomeEmail($user->toArray()));
   ```

   — enqueues the job as soon as that statement ends, because PHP destroys the temporary `PendingDispatch` object immediately (nothing holds a reference to it). Chaining works the same way:

   ```php
   dispatch(new SendWelcomeEmail($user->toArray()))
       ->onQueue('emails')
       ->priority(100)
       ->delay(30);
   // no ->run() needed — __destruct() calls it once the statement completes
   ```

   The one case where this bites: assigning the return value to a variable and holding onto it (`$pending = dispatch(new Job(...));`). The `PendingDispatch` now lives as long as `$pending` does, so `run()` won't fire until `$pending` goes out of scope — call `$pending->run()` explicitly if you need the row written now.

### Static ::dispatch() Has No Destructor Safety Net

`ShouldQueue::dispatch()` (the static one, not the global helper) has no `PendingDispatch` wrapper at all:

```php
SendWelcomeEmail::dispatch()->run(); // ->run() is mandatory
```

It returns a plain instance of your job class. If you forget the trailing `->run()`, nothing is ever enqueued and nothing warns you — there is no destructor here. This method also only works for jobs with a no-argument constructor, since it does `new self` internally. For anything with constructor arguments, use the global `dispatch()` helper or the manual flow:

```php
$job = new SendWelcomeEmail($user->toArray());
$job->init()->delay(5)->run(); // init() then run(), both explicit
```

## How a Job Is Serialized (SignedPayload)

`Eyika\Atom\Framework\Support\SignedPayload` (`src/Support/SignedPayload.php`) is what turns a job object into the string stored in the `payload` column, and back:

```php
public static function sign(mixed $value): string
{
    $data = base64_encode(serialize($value));
    return hash_hmac('sha256', $data, self::key()) . '|' . $data;
}

public static function verify(string $payload): mixed
{
    // splits on '|', recomputes the HMAC over $data with hash_equals(),
    // throws RuntimeException on mismatch, then unserialize(base64_decode($data))
}
```

The envelope is `"<hmac-sha256-hex>|<base64(serialize($job))>"`. The HMAC key is `config('app.key')` — `verify()` throws `RuntimeException('app.key must be configured...')` if it's empty, and `hash_equals()` guards against tampering. This exists specifically to stop an attacker who can write rows into the `jobs`/`failed_jobs` tables (e.g. via an unrelated SQL injection elsewhere) from smuggling a PHP object-injection gadget chain: `verify()` rejects any payload whose MAC doesn't match **before** `unserialize()` ever runs, so a forged payload's `__wakeup()`/`__destruct()` never fires.

Practical consequences:

- **`APP_KEY` must be set** (`php artisan key:generate`) or every `sign()`/`verify()` call throws.
- The **entire object** is `serialize()`d — every property, public or private, including whatever `Job_Queue` instance or connection you may have wired onto it if it's still attached at sign time (it normally isn't; `run()` and `bury()` sign `$this` after clearing/before the transient queue state matters). Keep constructor arguments small and re-fetchable — store IDs, not hydrated models, closures, or open PDO handles, none of which survive a round trip through `serialize()`/`unserialize()` reliably.
- Because `bury()` re-signs the object on every bury, whatever mutable state your `handle()` left on `$this` before calling `bury()` travels forward into the next attempt.

## Inside the Job_Queue Storage Engine

`Eyika\Atom\Framework\Foundation\Console\Job_Queue` (`src/Foundation/Console/Job_Queue.php`) is the actual storage/reservation engine. `ShouldQueue` and `JobRunner` only ever construct it with `queue_type = 'mysql'`, but the class itself is written to support three:

### Queue Types

```php
const QUEUE_TYPE_MYSQL = 'mysql';
const QUEUE_TYPE_SQLITE = 'sqlite';
const QUEUE_TYPE_BEANSTALKD = 'beanstalkd';
```

`mysql` and `sqlite` share the same SQL code paths throughout `Job_Queue` (same queries, same auto-created schema) — the only per-engine branching is `COMPRESS()`/`UNCOMPRESS()` (MySQL-only, guarded by `use_compression`) and how the "does this table exist" check is written (`SHOW TABLES LIKE` for MySQL vs `sqlite_master` for SQLite). `beanstalkd` is a genuinely different code path that delegates to a Beanstalkd client object's own API (`put()`, `reserve()`, `delete()`, `bury()`, `kickJob()`, `peekBuried()`, `useTube()`/`watch()`).

None of `ShouldQueue::init()`, `ShouldQueue::dispatch()`, or `JobRunner` ever pass `'sqlite'` or `'beanstalkd'` — if you want either, you have to build the dispatch/worker wiring yourself by instantiating `Job_Queue` directly (see the [Queues & Jobs](../queue#configqueuephp--connections-and-drivers) config note for the full picture of what's wired vs not).

### addJob() — The Insert

```php
public function addJob(string $payload, int $delay = 0, int $priority = 1024, int $time_to_retry = 60)
```

For `mysql`/`sqlite`, this computes four UTC timestamps and inserts one row:

- `added_dt` — now.
- `send_dt` — now + `$delay` seconds. This is the "not eligible until" timestamp the reservation query checks.
- `time_to_retry_dt` — now + `$time_to_retry` seconds. Not consulted on a job's *first* reservation (see below) — it only matters once `attempts >= 1`.
- `priority`, `is_reserved = 0`, `is_buried = 0`, `attempts = 0`.

Payload is bound as `COMPRESS(?)` when `use_compression` is true (the default) for MySQL, otherwise a plain `?`. Returns `['id' => <int>, 'payload' => $payload]`.

Recall `ShouldQueue::run()` calls `addJob($sclass, $this->delay, $this->priority, $this->delay)` — `$time_to_retry` is always the same value as `$delay`, there's no separate way to set it from the trait.

### getNextJobAndReserve() — The Reservation Query

This is the core of the worker's polling loop:

```sql
SELECT id, payload, `delay`, added_dt, send_dt, priority, is_reserved, reserved_dt, is_buried, buried_dt, attempts
FROM jobs
WHERE pipeline = ?
  AND send_dt <= ?
  AND is_buried = 0
  AND (is_reserved = 0 OR (is_reserved = 1 AND reserved_dt <= ?))
  AND (attempts = 0 OR (attempts >= 1 AND time_to_retry_dt <= ?))
ORDER BY priority ASC
LIMIT 1
```

Two bind parameters worth calling out precisely:

- The `reserved_dt <= ?` bind value is **`now - 1 minute`**, hardcoded (`strtotime('now -1 minutes UTC')`). So a reserved-but-unresolved row only becomes reservable again once it has been reserved for **at least one minute** — this is a fixed, non-configurable stale-reservation timeout that recovers jobs whose worker crashed mid-`handle()` (never called `delete()`/`bury()`/`fail()`) without needing anything else to intervene.
- The `time_to_retry_dt <= ?` check only applies once `attempts >= 1`. A brand-new job (`attempts = 0`) matches regardless of `time_to_retry_dt`, so the very first pickup only depends on `send_dt`.

When a row matches, `getNextJobAndReserve()` immediately runs a second statement to claim it:

```sql
UPDATE jobs SET is_reserved = 1, reserved_dt = ?, time_to_retry_dt = ?, attempts = attempts + 1 WHERE id = ?
```

`time_to_retry_dt` is recomputed here as `now + delay` seconds, using the row's **original** `delay` column (set once at insert, never changed by reservation). So if a worker reserves a job and then dies without resolving it, that job becomes reservable again after **`max(1 minute, delay seconds)`** — whichever is longer, since both conditions in the `WHERE` are ANDed together.

The returned array is `['id' => ..., 'attempts' => <post-increment count>, 'payload' => ...]` — this is exactly what gets passed to your job's `setJob()`.

### getNextBuriedJob() — The Retry Query

```sql
SELECT id, payload, `delay`, added_dt, send_dt, priority, attempts, is_reserved, reserved_dt, is_buried, buried_dt
FROM jobs
WHERE pipeline = ?
  AND send_dt <= ?
  AND is_buried = 1
  AND (attempts >= 1 AND time_to_retry_dt <= ?)
ORDER BY priority ASC
LIMIT 1
```

`JobRunner` only calls this when `getNextJobAndReserve()` found nothing — buried jobs are a second-priority pass, not interleaved with pending ones. On a match, it runs:

```sql
UPDATE jobs SET attempts = attempts + 1, time_to_retry_dt = ? WHERE id = ?
```

using the row's original `delay` column again to push `time_to_retry_dt` forward. Note this query **never resets `is_buried`** — only `kickJob()` does that, and nothing in `ShouldQueue`/`JobRunner` calls `kickJob()`. In practice that means: once a job is buried, it stays matched by `is_buried = 1` and gets handed to `handle()` again every time `time_to_retry_dt` elapses, until your `handle()` logic calls `delete()` or `fail()` (which remove the row) — calling `bury()` again just re-signs the payload and pushes the retry window out further.

### deleteJob(), buryJob(), failJob(), kickJob()

| Method | SQL effect |
| --- | --- |
| `deleteJob($job)` | `DELETE FROM jobs WHERE id = ?` |
| `buryJob($job, $time_to_retry)` | `UPDATE jobs SET payload = ?, is_buried = 1, buried_dt = ?, time_to_retry_dt = now+$time_to_retry, is_reserved = 0, reserved_dt = NULL WHERE id = ?` — also overwrites `payload` with whatever was passed in |
| `failJob($job)` | `INSERT INTO failed_jobs (pipeline, payload, added_dt, attempts) VALUES (...)`, returns the new row's id. Does **not** delete from `jobs` by itself — `ShouldQueue::fail()` calls `deleteJob()` separately right after. |
| `kickJob($job)` | `UPDATE jobs SET is_buried = 0, buried_dt = NULL WHERE id = ?` — un-buries a row. Available on `Job_Queue` but not exposed anywhere through `ShouldQueue` or the worker; you'd call it directly against your own `Job_Queue` instance if you needed it. |

### Auto-Created Tables

`runPreChecks()` calls `checkAndIfNecessaryCreateJobQueueTable()` before every operation (cached per-process via a static `self::$cache['job-queue-table-check']` flag so it only actually runs the existence checks once). For `mysql`/`sqlite`, it checks for and creates both tables if missing:

```sql
CREATE TABLE IF NOT EXISTS jobs (
    id int(11) NOT NULL AUTO_INCREMENT,
    pipeline varchar(500) NOT NULL,
    payload longblob NOT NULL,        -- longtext if use_compression = false
    `delay` smallint(8) UNSIGNED NOT NULL,
    added_dt datetime NOT NULL,       -- UTC
    send_dt datetime NOT NULL,        -- UTC
    priority int(11) NOT NULL,
    is_reserved tinyint(1) NOT NULL,
    reserved_dt datetime NULL,        -- UTC
    is_buried tinyint(1) NOT NULL,
    buried_dt datetime NULL,          -- UTC
    attempts tinyint(4) UNSIGNED NOT NULL,
    time_to_retry_dt datetime NULL,
    PRIMARY KEY (id),
    KEY pipeline_send_dt_is_buried_is_reserved (pipeline(75), send_dt, is_buried, is_reserved)
);

CREATE TABLE IF NOT EXISTS failed_jobs (
    id int(11) NOT NULL AUTO_INCREMENT,
    pipeline varchar(500) NOT NULL,
    payload longblob NOT NULL,        -- longtext if use_compression = false
    added_dt datetime NOT NULL,       -- UTC
    attempts tinyint(4) UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY pipeline (pipeline(75))
);
```

Table names default to `jobs` / `failed_jobs` but are overridable via the `Job_Queue` constructor options (`$options['mysql']['table_name']` / `['failed_table_name']`, or the `sqlite` equivalents) — `ShouldQueue` and `JobRunner` both hardcode `'table_name' => 'jobs'` and never set `failed_table_name`, so `failed_jobs` is always the failed-table name on the standard path.

### Compression

`use_compression` defaults to `true` (set in `Job_Queue::__construct()`'s default options merge) and only has an effect for `queue_type = 'mysql'`: payloads are written with `COMPRESS(?)` and read back with `UNCOMPRESS(payload) payload`, and the `payload` column is created as `longblob` instead of `longtext`. `sqlite` and `beanstalkd` ignore the flag — SQLite has no `COMPRESS()`/`UNCOMPRESS()` equivalent in this code path, so payloads are stored as plain (already base64/HMAC-wrapped) text there regardless.

## Running a Worker: queue:work / JobRunner

```bash
php artisan queue:work
```

The `work` command (`src/Foundation/Console/Commands/Queue/Work.php`, signature `queue:work`) does nothing but invoke `JobRunner`:

```php
class work extends Command
{
    public string $signature = 'queue:work';

    public function handle(): bool
    {
        try {
            call_user_func(new JobRunner);
        } catch (BaseConsoleException $e) {
            $this->error($e->getMessage());
            return !(bool)($e->getCode());
        }
        return true;
    }
}
```

`JobRunner::__invoke()` (`src/Foundation/Console/JobRunner.php`) is where the whole loop lives:

```php
public function __invoke()
{
    $dbtype = config('database.connections.mysql.driver');
    $dbhost = config('database.connections.mysql.host');
    $dbname = config('database.connections.mysql.database');
    $dbuser = config('database.connections.mysql.username');
    $dbpass = config('database.connections.mysql.password');
    // (charset/port are read too but unused in the DSN below)

    $job_Queue = new Job_Queue(Job_Queue::QUEUE_TYPE_MYSQL, [
        $dbtype => ['table_name' => 'jobs', 'use_compression' => true]
    ]);

    $pdo = new PDO("$dbtype:dbname=$dbname;host=$dbhost", $dbuser, $dbpass);
    $job_Queue->addQueueConnection($pdo);
    $job_Queue->watchPipeline('default');

    while (true) {
        if (!empty($job = $job_Queue->getNextJobAndReserve())) {
            $payload = $job['payload'];
            try {
                $job_obj = SignedPayload::verify($payload);
                if ($job_obj instanceof QueueInterface) {
                    $job_obj->setJob($job);
                    $job_obj->setQueue($job_Queue);
                    $job_obj->handle();
                } else {
                    info('job object is not an instance of Queue Interface');
                }
            } catch (Exception $e) {
                $job_Queue->buryJob($job, $job_obj->getDelay());
                throw $e;
            }
        } else if (!empty($job = $job_Queue->getNextBuriedJob())) {
            // identical verify → instanceof check → setJob/setQueue/handle() sequence
        } else {
            break;
        }
    }
}
```

Key behaviors to plan around:

1. **It connects with `config('database.connections.mysql.*')`**, not the `DB_*` env vars the dispatch side uses directly — in practice they resolve to the same database as long as your `config/database.php` reads those same env vars, which is the default skeleton.
2. **Pending jobs are drained before buried jobs.** Every loop iteration tries `getNextJobAndReserve()` first; `getNextBuriedJob()` only runs when that returns empty.
3. **The loop exits the moment both queries return nothing.** `queue:work` is not a resident daemon — it drains everything currently due and then the process ends. Run it on a schedule (see [Task Scheduling](../scheduling)):

   ```php
   Scheduler::command('queue:work')->everyTwoMinutes();
   ```

4. **`SignedPayload::verify()` failing is uncaught here** — a tampered or unsigned payload throws `RuntimeException` from inside `verify()`, which is *not* an `Exception` the surrounding `try`/`catch(Exception $e)` block catches in the pending-job branch (`RuntimeException` extends `Exception` in PHP, so it is in fact caught) — but note `$job_obj` won't be set yet if `verify()` is what threw, so the `catch` block's `$job_obj->getDelay()` call would itself fail with an "undefined variable" error. Keep payload integrity (a stable `APP_KEY`) as an operational requirement, not just a security one.
5. **Any exception from `handle()` buries the job and re-throws**, ending that `queue:work` invocation early — using `$job_obj->getDelay()` (the object's own `$delay` property) as the retry window. Any other due jobs are picked up on the next scheduled invocation.
6. **No flags.** `queue:work` takes no `--queue`, `--tries`, `--timeout`, or `--connection` options — the pipeline (`default`), table (`jobs`), and connection strategy are fixed by `JobRunner` itself. To drain a non-default pipeline you'd need your own command built around `Job_Queue`/a custom runner.

## Failed Jobs

`$this->fail()` inside `handle()` is the only path that populates `failed_jobs` on the standard flow — there's no automatic max-attempts policy, so you implement one yourself using the reserved job's `attempts` count (available on `$this->job['attempts']`, set by `setJob()` before `handle()` runs):

```php
public function handle()
{
    if (($this->job['attempts'] ?? 0) >= 5) {
        return $this->fail();      // → failed_jobs, removed from jobs
    }

    if (!$this->doWork()) {
        return $this->bury(30);    // transient failure, retry in 30s
    }

    $this->delete();               // success
}
```

There is no `queue:failed` / `queue:retry` command in this version of the framework. Inspecting or re-driving failures is a manual task against the `failed_jobs` table directly:

```php
use Eyika\Atom\Framework\Support\Database\DB;
use Eyika\Atom\Framework\Support\SignedPayload;

$failures = DB::table('failed_jobs')
    ->where('pipeline', '=', 'default')
    ->orderBy('added_dt', 'DESC')
    ->limit(50)
    ->get();

// To retry one: verify + re-sign + re-insert via a Job_Queue instance yourself,
// or just reconstruct the original job object and dispatch() a fresh copy.
```

`payload` in `failed_jobs` is compressed (when `use_compression` is on, which is the framework default) and HMAC-signed exactly like the `jobs` table — always go through `SignedPayload::verify()` to read it back rather than hand-decoding the column.

## config('queue.*') — What It Does Not Do

The app skeleton ships a `config/queue.php` with a Laravel-shaped `default`/`connections`/`failed` structure (`sync`, `database`, `beanstalkd`, `sqs`, `redis` entries). It is worth stating precisely, because it's easy to assume this file controls the behavior documented above — **it doesn't.** Nothing in `Job_Queue`, `ShouldQueue`, `PendingDispatch`, or `JobRunner` calls `config('queue...')`. There is no `Queue` facade or manager in this codebase that reads `'default'` and switches implementations.

What actually determines behavior, independent of that file:

- **Dispatch side** (`ShouldQueue::init()` / static `dispatch()` / `PendingDispatch`): hardcoded `Job_Queue::QUEUE_TYPE_MYSQL`, connection built from `env('DB_DATABASE')`, `env('DB_HOST')`, `env('DB_USERNAME')`, `env('DB_PASSWORD')`.
- **Worker side** (`JobRunner`): hardcoded `Job_Queue::QUEUE_TYPE_MYSQL`, connection built from `config('database.connections.mysql.*')`.
- **Table names**: `jobs` / `failed_jobs`, hardcoded in both `ShouldQueue` and `JobRunner`'s `Job_Queue` constructor options.
- **Pipeline**: `default`, unless you call `onQueue()`.

Setting `QUEUE_CONNECTION=redis` (or `sqs`/`beanstalkd`/`database`) in `.env` has no effect on any of this — see the full breakdown, including the file's own contents, in [Queues & Jobs § Configuration](../queue#configuration). If you need a genuinely different backend, the way to get there is to bypass `ShouldQueue`'s `init()`/`dispatch()` and construct + wire your own `Job_Queue` (e.g. `QUEUE_TYPE_SQLITE` or `QUEUE_TYPE_BEANSTALKD`) and your own worker loop modeled on `JobRunner`, rather than expecting `config('queue.*')` to switch anything.

## Gotchas

1. **Forgetting `implements QueueInterface`.** The trait alone isn't enough — `JobRunner` checks `instanceof QueueInterface` and silently logs `'job object is not an instance of Queue Interface'` via `info()` instead of running `handle()`.
2. **Holding a `PendingDispatch` in a variable.** `$pending = dispatch(new Job(...));` defers `run()` until `$pending` is destroyed/goes out of scope — call `$pending->run()` explicitly if you need it enqueued immediately.
3. **`ShouldQueue::dispatch()` (static) never auto-runs.** Unlike the global `dispatch()` helper, forgetting `->run()` here means the job is silently never enqueued.
4. **`onConnection()` doesn't change `queue_type`.** Passing a `SQLite3` handle to a job whose `Job_Queue` was constructed as `'mysql'` (the only way `ShouldQueue` constructs it) will break on the MySQL-only table-creation/compression SQL.
5. **Every path through `handle()` should end in `delete()`, `bury()`, or `fail()`.** If none run, the row stays `is_reserved = 1` and isn't picked up again until the hardcoded stale-reservation window (`max(1 minute, delay seconds)`) lapses on its own.
6. **Buried jobs never auto-unbury.** `getNextBuriedJob()` doesn't reset `is_buried`; only `kickJob()` does, and nothing in the standard flow calls it. A buried job stays in the `is_buried = 1` retry cycle until your own `handle()` logic calls `delete()` or `fail()`.
7. **Payloads must stay serializable.** Constructor properties should be scalars/arrays/IDs — closures, open PDO handles, and other non-serializable state won't round-trip through `SignedPayload::sign()`/`verify()`.
8. **`APP_KEY` is a hard dependency.** An empty `app.key` makes `SignedPayload::sign()`/`verify()` throw — every dispatch and every worker pickup needs it set.
9. **`queue:work` exits when the queue is empty.** It is not a long-running process; schedule it (`Scheduler::command('queue:work')->everyTwoMinutes()`) rather than expecting one invocation to keep watching indefinitely.

## Conclusion

The whole queued-jobs feature is three small pieces wired together: `SignedPayload` for tamper-proof serialization, `Job_Queue` as the MySQL-backed (in practice) reservation engine with its own SQL for insert/reserve/bury/delete/fail, and `ShouldQueue` + `PendingDispatch` as the ergonomic layer on top that most application code touches. Nothing here reads `config('queue.*')` — that file describes an aspirational multi-driver future, not the current runtime. If you're building a job class, implement `QueueInterface`, keep its state serializable, resolve every `handle()` path with `delete()`/`bury()`/`fail()`, and dispatch with the global `dispatch()` helper unless you have a specific reason to reach for the static `::dispatch()` or the manual `init()->run()` flow. For the day-to-day usage guide this page assumes, see [Queues & Jobs](../queue).
