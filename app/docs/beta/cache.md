# Cache

Atom's cache system gives you a single, driver-agnostic API for storing values you don't want to recompute or re-fetch on every request — query results, computed aggregates, API responses, rendered fragments, anything expensive. You reach it through the `Cache` facade, which is **PSR-6 compliant**: instead of a plain `get`/`put` pair, you work with `CacheItem` objects — you fetch an item for a key, ask whether it was a hit, read or set its value, give it an expiration, and save it back.

> Unlike some other frameworks, Atom does **not** ship a global `cache()` helper function. All access goes through the `Cache` facade (or by instantiating `Eyika\Atom\Framework\Support\Cache\Cache` directly — see [Switching Stores at Runtime](#switching-stores-at-runtime)).

---

## Table of Contents

1. [Configuration](#configuration)
2. [The Cache Facade](#the-cache-facade)
3. [Storing Items](#storing-items)
4. [Retrieving Items](#retrieving-items)
5. [Read-or-Compute Pattern](#read-or-compute-pattern)
6. [Cache Expiration](#cache-expiration)
7. [Checking Existence](#checking-existence)
8. [Deleting & Clearing](#deleting--clearing)
9. [Deferred Writes](#deferred-writes)
10. [Cache Drivers](#cache-drivers)
11. [Switching Stores at Runtime](#switching-stores-at-runtime)
12. [Best Practices](#best-practices)

---

## Configuration

Cache stores are defined in `config/cache.php`. The `default` key names the store the `Cache` facade uses when you don't ask for a specific one, and `stores` lists every named backend along with its own driver and options.

```php
use Eyika\Atom\Framework\Support\Str;

return [

    'default' => env('CACHE_DRIVER', 'file'),

    'stores' => [

        'file' => [
            'driver' => 'file',
            'path' => storage_path('framework/cache/data'),
        ],

        'database' => [
            'driver' => 'database',
            'table' => 'cache',
            'connection' => null,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'cache',
        ],

        'memcached' => [
            'driver' => 'memcached',
            'servers' => [
                [
                    'host' => env('MEMCACHED_HOST', '127.0.0.1'),
                    'port' => env('MEMCACHED_PORT', 11211),
                    'weight' => 100,
                ],
            ],
        ],

        'apc' => [
            'driver' => 'apc',
        ],

        'array' => [
            'driver' => 'array',
            'serialize' => false,
        ],

        'dynamodb' => [
            'driver' => 'dynamodb',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'table' => env('DYNAMODB_CACHE_TABLE', 'cache'),
        ],
    ],

    // Prefixed onto keys for stores where namespacing matters (see note below).
    'prefix' => env('CACHE_PREFIX', Str::slug(env('APP_NAME', 'atom'), '_') . '_cache_'),

];
```

- **`default`**: which entry in `stores` the `Cache` facade resolves to. Overridable with the `CACHE_DRIVER` env variable.
- **`stores`**: a named map of backends. Every store needs a `driver` key; the rest of the array is driver-specific configuration (shown per driver below).
- **`prefix`**: a namespace prefix, useful when several applications share the same backing store (e.g. the same Redis instance or database).

> `prefix` is currently only *read* by the `file` driver's constructor and isn't applied to cache keys yet — don't rely on it to namespace keys across applications sharing a store today.

When you construct a `Cache` (directly, or via the facade resolving `'cache'` out of the container), it reads `config('cache.stores')[$store ?? config('cache.default')]` and instantiates the matching adapter. Only these seven `driver` values are wired up: `apc`, `array`, `database`, `file`, `memcached`, `redis`, `dynamodb`. If `driver` is missing or empty, construction throws `Eyika\Atom\Framework\Exceptions\Cache\InvalidConfigException`.

> Some published config stubs also list an `octane` store. There's no adapter registered for that driver name — selecting it doesn't throw the friendly config exception above, it fails hard when the framework tries to instantiate a nonexistent adapter class. Stick to the seven supported drivers.

---

## The Cache Facade

Import the facade before using it:

```php
use Eyika\Atom\Framework\Support\Facade\Cache;
```

Every call goes to the store named by `cache.default` (or whichever store you attached the facade to — see [Switching Stores at Runtime](#switching-stores-at-runtime)). The facade exposes:

| Method | Description |
|---|---|
| `getItem(string $key): CacheItem` | Fetch the item for a key. A miss returns an *empty*, non-hit item — never `null`. |
| `getItems(array $keys = []): iterable` | Fetch several items at once, keyed by the original keys. |
| `hasItem(string $key): bool` | Check whether a key exists (and isn't expired) without reading its value. |
| `save($item): bool` | Persist a `CacheItem` immediately. |
| `setItem($item): bool` | Alias for `save()`. |
| `saveDeferred($item): bool` | Queue a `CacheItem` to be persisted on the next `commit()`. |
| `commit(): bool` | Persist every item queued with `saveDeferred()`. |
| `deleteItem(string $key): bool` | Remove a single item. |
| `deleteItems(array $keys): bool` | Remove several items. |
| `clear(): bool` | Remove everything in the store. |
| `instance(): self` | Return the underlying `Cache` object (implements `CacheInterface`), useful for handing the cache to an API that wants the object itself — e.g. [`Storage::cache()`](filesystem). |

---

## Storing Items

To store a value: fetch (or create) its item, set the value, give it an expiration, then save it.

```php
use Eyika\Atom\Framework\Support\Facade\Cache;

$item = Cache::getItem('dashboard.summary');
$item->set(['orders' => 128, 'revenue' => 4820.50])->expiresAfter(600); // 10 minutes

Cache::save($item);
```

`CacheItem::set()` returns the item itself, so it chains with `expiresAfter()`/`expiresAt()` before you call `Cache::save()`.

```php
use App\Models\Product;

$item = Cache::getItem('products.featured');
$products = Product::where('featured', '=', true)->get();

$item->set($products)->expiresAfter(300);
Cache::save($item);
```

> `getItem()` always returns a `CacheItem`, whether the key exists or not — there's nothing to null-check before calling `set()` on it. It's `get()` (below) that needs the hit check.

---

## Retrieving Items

Fetch the item, check `isHit()`, then read `get()`. A miss — or an item whose expiration has passed — reports `isHit() === false` and `get()` returns `null`.

```php
$item = Cache::getItem('dashboard.summary');

if ($item->isHit()) {
    $summary = $item->get();
} else {
    // not cached (or expired) — compute and store it
}
```

> Always gate on `isHit()` before trusting `get()`. A miss returns `null`, which is indistinguishable from a legitimately cached `null` value if you skip the check.

`getItems()` batches several lookups into one iterable, keyed by the keys you passed in:

```php
$items = Cache::getItems(['user.1.profile', 'user.2.profile']);

foreach ($items as $key => $item) {
    if ($item->isHit()) {
        // ...
    }
}
```

---

## Read-or-Compute Pattern

PSR-6 has no built-in `remember()` shortcut, but the "read from cache, or compute and store" pattern is a handful of lines:

```php
use Eyika\Atom\Framework\Support\Facade\Cache;
use App\Models\User;

$item = Cache::getItem('users.active_count');

if (!$item->isHit()) {
    $count = User::where('status', '=', 'active')->count();
    $item->set($count)->expiresAfter(300);
    Cache::save($item);
}

$activeCount = $item->get();
```

This fetches the value if it's already cached, otherwise computes it, caches it for 5 minutes, and either way leaves `$activeCount` holding the right value. If you find yourself repeating this shape, wrap it in your own small helper in your app rather than reaching for one the framework doesn't provide:

```php
function cache_remember(string $key, int $ttl, callable $compute): mixed
{
    $item = Cache::getItem($key);

    if (!$item->isHit()) {
        $item->set($compute())->expiresAfter($ttl);
        Cache::save($item);
    }

    return $item->get();
}

$activeCount = cache_remember('users.active_count', 300, fn () => User::where('status', '=', 'active')->count());
```

---

## Cache Expiration

`CacheItem` carries its own expiration:

- **`expiresAfter(int|\DateInterval|null $time)`**: a relative TTL — seconds, a `\DateInterval`, or `null` to clear any expiration you'd previously set.
- **`expiresAt(?\DateTimeInterface $expiration)`**: an absolute expiry moment, or `null` to clear it.
- **`getExpiration(): ?int`**: the resolved expiration as a Unix timestamp, or `null` if none has been set.

```php
// Relative TTL — 30 minutes
$item = Cache::getItem('key');
$item->set('value')->expiresAfter(30 * 60);
Cache::save($item);

// Absolute expiry
$item->set('value')->expiresAt((new DateTime())->modify('+1 day'));
Cache::save($item);

// A DateInterval works too
$item->set('value')->expiresAfter(new DateInterval('PT1H')); // 1 hour
Cache::save($item);
```

`isHit()` re-checks the expiration on every call — an item read back after its expiry has passed reports `isHit() === false`, even if the underlying store hasn't physically evicted it yet.

> **Always set an explicit TTL, and verify it empirically for the store you're using.** A freshly-created `CacheItem` (or one where you call `expiresAfter(null)`) has no expiration set at all — it's *not* the same as "never expires" on every driver, and how each adapter turns `getExpiration()` into an on-store expiry differs by driver. If a tight, precisely-honored TTL matters for your use case, write a quick sanity check against your chosen store rather than assuming the number of seconds you pass to `expiresAfter()` is honored exactly — and for correctness-sensitive caches, don't rely on TTL alone: explicitly `deleteItem()` stale keys when the underlying data changes.

---

## Checking Existence

`hasItem()` is a cheaper existence check when you don't need the value itself:

```php
if (Cache::hasItem('dashboard.summary')) {
    // key is present and not expired
}
```

---

## Deleting & Clearing

```php
Cache::deleteItem('key');                 // remove a specific item
Cache::deleteItems(['key1', 'key2']);     // remove several items
Cache::clear();                           // remove everything in the store
```

`deleteItem()`/`clear()` return `true`/`false` depending on the outcome; `deleteItems()` loops over each key and returns `true` once all deletes have been attempted.

---

## Deferred Writes

For batch scenarios, queue items and flush them together in one call:

```php
$a = Cache::getItem('report.a'); $a->set(1);
$b = Cache::getItem('report.b'); $b->set(2);

Cache::saveDeferred($a);
Cache::saveDeferred($b);

Cache::commit(); // both written now, queue cleared
```

`saveDeferred()` only queues the item in memory — nothing is persisted until you call `commit()` yourself. There's no automatic flush at the end of the request, so if you defer a write, make sure something in your request lifecycle actually calls `commit()`.

---

## Cache Drivers

Select a driver by setting its `driver` key in `config/cache.php`. Each maps to an internal adapter class that implements the same `CacheInterface`.

### `file` (the default)

Stores each item as a serialized file under `stores.file.path` (default: `storage_path('framework/cache/data')`), named by the MD5 hash of the key. Built on the same Flysystem-backed `File` abstraction used by [Storage](filesystem). No extra extension required.

### `database`

Stores items in a table (`stores.database.table`, default `_cache`) via the query layer — see [Database](database/models). If the table doesn't exist yet, it's created lazily on first use with columns `key`, `expire`, `value`.

> As shipped, the `database` driver's `getItem()`/`hasItem()` don't reliably read back a previously cached value — the underlying database helper it calls into was built for a "compare-and-populate" pattern rather than a bare lookup, so a real hit can come back reporting as a miss (or vice versa). `save()`, `deleteItem()`, and `clear()` behave as expected. Until this is tightened up, prefer `file`, `array`, `redis`, `memcached`, or `apc` for anything where you need to reliably read the cached value back.

### `redis`

Requires [`predis/predis`](https://github.com/predis/predis). Uses `SETEX` to store values with a TTL and `json_encode`/`json_decode` for the value itself.

> The `redis` driver currently connects to a hard-coded `127.0.0.1:6379` — the `connection` value under `stores.redis` isn't wired up to point it elsewhere yet.

### `memcached`

Requires the `memcached` PHP extension. Reads `stores.memcached.servers` (an array of `['host' => ..., 'port' => ..., 'weight' => ...]`) and registers each with the Memcached client. Only `host`/`port` are currently applied when adding servers; `weight`, `persistent_id`, `sasl`, and `options` are accepted in config but not yet forwarded to the client.

### `apc`

Requires the `apcu` PHP extension (`apcu_fetch`/`apcu_store`/etc.). Throws `Eyika\Atom\Framework\Exceptions\Cache\IncompleteInstallationException` at construction time if the extension isn't installed or enabled.

### `dynamodb`

Requires [`aws/aws-sdk-php`](https://github.com/aws/aws-sdk-php). Needs `stores.dynamodb.region`, `key`, `secret`, and `table` — the table must already exist with a partition key named `id`.

### `array`

An in-memory, per-process store with no persistence — resets on every new request/process. Good for tests and short-lived, request-scoped memoization. Set `stores.array.serialize` to `true` to force values through `serialize()`/`unserialize()` on the way in and out (useful if you want to catch code that mutates a cached object by reference, since without it you get the exact same in-memory object back).

> As shipped, calling `hasItem()` (and therefore `getItem()`, which calls `hasItem()` internally) for a key you've already `save()`d on the `array` store currently errors, due to an internal type mismatch in how the store checks expiration. Until that's fixed, treat the `array` driver as best suited to short, disposable test state rather than a read/write round trip through the facade.

---

## Switching Stores at Runtime

The facade always talks to `cache.default`. To reach a different named store, construct a `Cache` instance directly rather than going through the facade:

```php
use Eyika\Atom\Framework\Support\Cache\Cache;

$redis = new Cache('redis');

$item = $redis->getItem('session.heartbeat');
$item->set(time())->expiresAfter(30);
$redis->save($item);
```

Passing an unknown store name throws `InvalidConfigException` the same way an unconfigured `default` would.

---

## Best Practices

- **Cache only what's expensive.** Reach for the cache for data that's costly to compute or fetch — database aggregates, external API responses, rendered fragments — not for values that are already cheap to read.
- **Always check `isHit()` before `get()`.** A miss returns `null`, which may be indistinguishable from a legitimately cached `null` if you skip the check.
- **Set an explicit, verified TTL.** Don't assume a driver treats "no expiration set" as "forever" — see the [Cache Expiration](#cache-expiration) note above.
- **Avoid caching sensitive data** in a store that other processes or applications might share, especially with `prefix` not yet namespacing keys for you.
- **Prefer `deleteItem()` over waiting out a TTL** whenever you know the underlying data just changed — invalidate explicitly rather than trusting a timer for correctness-sensitive data.
- **Reach for `saveDeferred()`/`commit()`** only when you're genuinely batching several writes together; a single `save()` call is simpler and immediate.

By caching the right things for the right amount of time, you can meaningfully cut database load and response times, especially under high traffic.