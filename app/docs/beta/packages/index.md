# Official Packages

Beyond the core framework, Eyika ships a small set of **first-party packages** — optional, independently versioned Composer packages that extend Atom with production infrastructure you'd otherwise have to build or bolt on from the Node/Go ecosystem. Each package auto-discovers its service provider via the `extra.atom.providers` block in its own `composer.json` (see [Package Auto-Discovery](../configuration#package-auto-discovery)), so installing one is just `composer require` — no manual provider registration.

This page indexes the available packages. If you want to build your own installable Atom package, see [Writing Packages](writing).

## Table of Contents

- [atom-octane](#atom-octane) — persistent-worker application server
- [atom-reverb](#atom-reverb) — WebSocket broadcast server

---

## atom-octane

**High-performance persistent worker for Atom** — boots the application **once** and serves many requests against the already-booted kernel, resetting per-request state in between, instead of the traditional PHP-FPM model of bootstrapping the whole framework on every request. This is where the framework's worker-safety primitives (`Application::flushRequestState()`, capturable responses) pay off: the same `Worker` class runs unmodified under four different runtimes — a dependency-free **native** pcntl fork pool, **Swoole**, **RoadRunner**, or **FrankenPHP** — so you pick the concurrency model that fits your deployment without rewriting application code.

- **GitHub:** [github.com/eyika/atom-octane](https://github.com/eyika/atom-octane)
- **Require:**
  ```bash
  composer require eyika/atom-octane
  ```

### Runtimes

| Runtime | Concurrency | Notes |
|---|---|---|
| `native` | pcntl fork pool | Dependency-free, pure PHP. Needs `ext-pcntl` (POSIX only) for real concurrency; single-process without it. |
| `swoole` | Swoole workers | Needs `ext-swoole`; coroutines run **off** — the framework's static state is not coroutine-safe. |
| `roadrunner` | RoadRunner process pool | Needs `spiral/roadrunner-http` + `nyholm/psr7`; the `rr` Go binary manages the pool, keep-alive, TLS, and reload. |
| `frankenphp` | FrankenPHP workers | Caddy/Go server; HTTP/2+3, automatic HTTPS. |

### Quick start

```bash
composer require eyika/atom-octane
php artisan vendor:publish --tag=octane-config   # optional: config/octane.php

php artisan octane:serve                                 # uses config's server
php artisan octane:serve --server=swoole --workers=8     # override
php artisan octane:serve --host=0.0.0.0 --port=8080 --max-requests=1000
```

Key `config/octane.php` settings (all env-overridable):

```php
'server'          => env('OCTANE_SERVER', 'native'),      // native|swoole|roadrunner|frankenphp
'host'            => env('OCTANE_HOST', '127.0.0.1'),
'port'            => (int) env('OCTANE_PORT', 8090),
'workers'         => env('OCTANE_WORKERS', 'auto'),       // native/swoole; "auto" = CPU cores
'max_requests'    => (int) env('OCTANE_MAX_REQUESTS', 500),  // recycle worker after N requests
'max_memory'      => (int) env('OCTANE_MAX_MEMORY', 0),      // recycle over N MB (0 = off)
'request_timeout' => (int) env('OCTANE_REQUEST_TIMEOUT', 30),
```

**Worker recycling** bounds the slow memory growth every long-lived PHP process accumulates (fragmentation, caches, leaks in app or third-party code): a worker gracefully restarts after `max_requests` requests or once it crosses `max_memory`.

> **Caveat:** the framework's static state is not coroutine/async-safe, so Swoole runs with coroutines off — concurrency is bounded by worker count, one request per worker at a time. Any app singleton that holds per-request state needs to be reset yourself (a provider's boot hook, or the container's `scoped()` bindings); `flushRequestState()` only clears *framework* state, not your own.

---

## atom-reverb

**Lightweight, dependency-free WebSocket broadcast server for Atom** — a Pusher-protocol-compatible server built purely on PHP's `stream_socket_server` + `stream_select` (no Ratchet, no ReactPHP), hardened for real deployments: private/presence channel authorisation over HMAC, presence membership with member events, HMAC-signed broadcast ingest from your app, non-blocking writes with back-pressure, ping/pong heartbeats, fragmented-message reassembly, and horizontal scaling across nodes via a **Redis pub/sub backplane**. Because it speaks the Pusher protocol, standard `pusher-js` clients (and Laravel Echo) connect to it without modification.

- **GitHub:** [github.com/eyika/atom-reverb](https://github.com/eyika/atom-reverb)
- **Require:**
  ```bash
  composer require eyika/atom-reverb
  ```

### Quick start

```bash
composer require eyika/atom-reverb
php artisan vendor:publish --tag=reverb-config   # config/reverb.php
```

```dotenv
REVERB_APP_KEY=your-app-key
REVERB_APP_SECRET=a-long-random-secret   # signs channel auth + ingest — REQUIRED in production
REVERB_PORT=8091
REVERB_INGEST_PORT=8092
```

```bash
php artisan reverb:start
php artisan reverb:start --host=0.0.0.0 --ws-port=8091 --ingest-port=8092
```

Run it behind a process supervisor (systemd/supervisor) with nginx/Caddy in front for TLS. Expose only the WS port publicly and keep the **ingest port firewalled** to your app servers.

### Broadcasting and presence channels

```php
use Eyika\Atom\Reverb\Support\Broadcast;

Broadcast::send('orders', 'OrderShipped', ['id' => 42]);   // or broadcast('orders', 'OrderShipped', [...])
```

Private (`private-`) and presence (`presence-`) channels require an authorisation endpoint backed by `BroadcastManager::channelAuth()`:

```php
use Eyika\Atom\Reverb\Broadcasting\BroadcastManager;

// POST /broadcasting/auth  { socket_id, channel_name }
$auth = app(BroadcastManager::class)->channelAuth(
    $request->input('socket_id'),
    $request->input('channel_name'),
    ['user_id' => $user->id, 'user_info' => ['name' => $user->name]] // presence only
);

return JsonResponse::ok('', $auth);
```

### Redis backplane

Enable Redis so a broadcast on one node reaches clients connected to every node — including cross-node presence, which is aggregated with Lua-atomic reference counting and de-duplicated per `user_id`:

```dotenv
REVERB_REDIS=true
REVERB_REDIS_HOST=127.0.0.1
REVERB_REDIS_PORT=6379
```

> Reverb has its own `Eyika\Atom\Reverb\Contracts\ShouldBroadcast` contract (`broadcastOn()`/`broadcastAs()`/`broadcastWith()`), separate from the core framework's broadcasting contract described in [Broadcasting](../broadcasting). Use either the framework's built-in `BroadcastManager` (log/Pusher drivers) or atom-reverb for a given event flow — not both at once. Full driver details, the browser-side WebSocket client example, and how the two systems relate live in [Broadcasting §6](../broadcasting).

---

Each package's own README links back to its section on this page, so wherever you land — GitHub or Packagist — you can find your way back to the canonical Atom docs.
