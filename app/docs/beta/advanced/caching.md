## Caching

Caching is an essential technique for improving the performance and scalability of web applications. By storing frequently accessed data in a temporary storage (cache), you can reduce the number of database queries and external API calls, making your application faster and more efficient.

Atom's cache is **PSR-6 compliant**. Applications require the `psr/cache` package, and the API is built around *cache items* (`CacheItem`) rather than plain get/put calls. The cache is bound into the container as `'cache'` by the `CacheServiceProvider`, and is reached through the `Cache` facade.

### 1. **Caching Basics**
   The framework provides a unified, PSR-6 API to interact with various caching backends. You work with `CacheItem` objects: you fetch an item, ask whether it was a hit, read its value, mutate it, and save it back.

   **Key Concepts:**
   - **Cache Stores:** Named backends configured in `config/cache.php` (file, redis, database, memcached, apc, dynamodb, array).
   - **Cache Drivers:** Each store names a driver that determines where and how data is cached.
   - **Cache Items:** A `CacheItem` wraps a key, its value, hit state, and expiration.

   Import the facade before using it:
   ```php
   use Eyika\Atom\Framework\Support\Facade\Cache;
   ```

### 2. **Configuring Cache Stores**
   Cache stores are defined in `config/cache.php`. The `default` key selects which store the `Cache` facade uses, and `stores` lists every available backend.

   **Key Concepts:**
   - Defining multiple cache stores (e.g., `file`, `redis`, `database`).
   - Selecting the default cache store via the `CACHE_DRIVER` env variable.
   - Configuring store-specific options (e.g., Redis connection settings).

   Example of the `config/cache.php` shape:
   ```php
   return [
       'default' => env('CACHE_DRIVER', 'file'),

       'stores' => [
           'file' => [
               'driver' => 'file',
               'path' => storage_path('framework/cache/data'),
           ],
           'redis' => [
               'driver' => 'redis',
               'connection' => 'cache',
           ],
       ],
   ];
   ```

   To target a store other than the default, construct a `Cache` instance for it directly:
   ```php
   use Eyika\Atom\Framework\Support\Cache\Cache;

   $redis = new Cache('redis');
   ```

### 3. **Storing Data in the Cache**
   To store a value, fetch (or create) its item, set the value, optionally give it an expiration, then save it.

   **Key Concepts:**
   - **`Cache::getItem()`**: Fetch the item for a key (a miss returns an empty item).
   - **`CacheItem::set()`**: Set the item's value (returns the item for chaining).
   - **`CacheItem::expiresAfter()` / `expiresAt()`**: Set the expiration.
   - **`Cache::save()`**: Persist the item to the store.

   Example:
   ```php
   $item = Cache::getItem('key');
   $item->set('value')->expiresAfter(600); // expires in 600 seconds

   Cache::save($item);
   ```

   The above stores a value in the cache for 10 minutes.

### 4. **Retrieving Data from the Cache**
   To retrieve cached data, fetch the item and check `isHit()` before reading `get()`. A miss (or an expired item) reports `isHit() === false` and `get()` returns `null`.

   **Key Concepts:**
   - **`Cache::getItem()`**: Retrieve the item for a key.
   - **`CacheItem::isHit()`**: Whether the item was present and unexpired.
   - **`CacheItem::get()`**: The stored value (or `null` on a miss).
   - **`Cache::hasItem()`**: Check existence without reading the value.

   Example:
   ```php
   $item = Cache::getItem('key');

   if ($item->isHit()) {
       $value = $item->get();
   } else {
       // not cached — compute and store it
   }
   ```

### 5. **Read-or-Compute Pattern**
   PSR-6 has no built-in `remember()` helper, but the "read from cache, or compute and store" pattern is a few lines:

   ```php
   $item = Cache::getItem('users.all');

   if (!$item->isHit()) {
       $users = User::all();             // expensive query
       $item->set($users)->expiresAfter(600);
       Cache::save($item);
   }

   $users = $item->get();
   ```

   This retrieves the value if it exists, otherwise computes it, caches it for 600 seconds, and returns it.

### 6. **Cache Expiration**
   Cache expiration defines how long a cached item stays valid before it is treated as a miss and removed on the next access.

   **Key Concepts:**
   - **TTL (Time To Live):** `expiresAfter()` accepts a number of seconds, a `DateInterval`, or `null` for no expiration.
   - **Absolute expiry:** `expiresAt()` accepts a `DateTimeInterface`.

   Examples:
   ```php
   // Relative TTL — 30 minutes
   $item = Cache::getItem('key');
   $item->set('value')->expiresAfter(30 * 60);
   Cache::save($item);

   // Absolute expiry
   $item->set('value')->expiresAt((new DateTime())->modify('+1 day'));
   Cache::save($item);

   // Never expires
   $item->set('value')->expiresAfter(null);
   Cache::save($item);
   ```

### 7. **Cache Drivers**
   The framework ships several drivers, so you can choose the backend that best suits your needs:

   - **File** (`file`): stores cache on the filesystem (the default store).
   - **Database** (`database`): stores cache in a database table.
   - **Redis** (`redis`): a high-performance in-memory store.
   - **Memcached** (`memcached`): another high-performance in-memory store.
   - **APC** (`apc`): in-process opcode-cache-backed store.
   - **DynamoDB** (`dynamodb`): AWS-hosted key/value store.
   - **Array** (`array`): an in-memory store useful for testing.

   Each maps to an adapter class (`FileCache`, `RedisCache`, `DbCache`, `MemcachedCache`, `ApcCache`, `DynamodbCache`, `ArrayCache`). Select the default by setting `default` in `config/cache.php`.

### 8. **Deleting and Clearing the Cache**
   To remove cached data, delete individual items or clear the whole store.

   **Key Concepts:**
   - **`Cache::deleteItem()`**: Remove a single cached item.
   - **`Cache::deleteItems()`**: Remove several items at once.
   - **`Cache::clear()`**: Remove every item in the store.

   Example:
   ```php
   Cache::deleteItem('key');                 // remove a specific item
   Cache::deleteItems(['key1', 'key2']);     // remove several items
   Cache::clear();                           // clear the entire store
   ```

### 9. **Deferred Writes**
   For batch scenarios you can queue items and flush them together, which lets a backend persist them in one round trip.

   **Key Concepts:**
   - **`Cache::saveDeferred()`**: Queue an item to be persisted later.
   - **`Cache::commit()`**: Persist every queued item.

   Example:
   ```php
   $a = Cache::getItem('a'); $a->set(1);
   $b = Cache::getItem('b'); $b->set(2);

   Cache::saveDeferred($a);
   Cache::saveDeferred($b);
   Cache::commit(); // both written now
   ```

### Caching Best Practices:
   - **Cache Only What's Expensive:** Cache data that is expensive to compute or fetch, like database queries or external API calls.
   - **Avoid Caching Sensitive Data:** Never store sensitive or user-specific information in a shared or public cache store.
   - **Set Proper Expiration Times:** Always set an expiration so data stays fresh and relevant.
   - **Always Check `isHit()`:** Never trust `get()` without first checking `isHit()` — a miss returns `null`, which may be a legitimate cached value elsewhere.

By leveraging the caching system effectively, you can significantly improve the performance and responsiveness of your application, especially under high traffic scenarios.
