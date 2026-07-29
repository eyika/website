## Broadcasting In Atom

Broadcasting lets your server push events to clients in real time — order updates, notifications, live dashboards — without the client polling. In Atom, an event object that implements `ShouldBroadcast` is dispatched through the event system and forwarded to a **broadcast driver**, which delivers it to a channel. The framework ships a `log` driver (for local development) and a `pusher` driver (for production over Pusher Channels).

For a fully self-hosted alternative — no Pusher account, no Redis — there is a separate `eyika/atom-reverb` package that runs a lightweight WebSocket server. It is covered at the end of this page.

---

### 1. **Defining a broadcastable event**

An event broadcasts when it implements the `ShouldBroadcast` contract, which requires a single `broadcastOn()` method returning the channels to publish on:

```php
<?php

namespace App\Events;

use Eyika\Atom\Framework\Broadcasting\Contracts\ShouldBroadcast;

class OrderShipped implements ShouldBroadcast
{
    public function __construct(
        public int $orderId,
        public string $status,
    ) {}

    /**
     * The channels this event should broadcast on.
     */
    public function broadcastOn(): array
    {
        return ['orders'];
    }
}
```

Dispatch it through the event system exactly like any other event (see [Events](advanced/events)):

```php
event(new OrderShipped(42, 'shipped'));
```

When the dispatcher runs an event, it checks whether the event implements `ShouldBroadcast`; if so, it forwards it to the broadcaster automatically:

```php
// Foundation\Event\Dispatcher (framework internals)
if ($eventObject instanceof ShouldBroadcast) {
    Broadcast::broadcast(
        $eventObject->broadcastOn(),      // channels
        get_class($eventObject),          // event name
        get_object_vars($eventObject)     // payload = the event's public properties
    );
}
```

> Two things follow from this. First, the **event name** delivered to clients is the fully-qualified class name (`App\Events\OrderShipped`). Second, the **payload** is `get_object_vars()` of the event — i.e. every **public** property. Put exactly the data you want on the wire in public properties, and keep anything private out of the payload.

---

### 2. **Broadcasting directly**

You don't have to go through an event. The `Broadcast` facade (and the global `broadcast()` helper) publish immediately:

```php
use Eyika\Atom\Framework\Support\Facade\Broadcast;

Broadcast::broadcast(['orders'], 'OrderShipped', ['id' => 42, 'status' => 'shipped']);

// Equivalent global helper:
broadcast(['orders'], 'OrderShipped', ['id' => 42, 'status' => 'shipped']);
```

The signature is `broadcast(array $channels, $event, array $payload = [])` for both. The first argument is always an **array** of channel names.

---

### 3. **Drivers**

The active driver is resolved by `config('broadcasting.default')`. The `BroadcastManager` resolves exactly two driver names:

| Driver   | Class                | Behavior |
|----------|----------------------|----------|
| `log`    | `LogBroadcaster`     | Writes the event to the application log via `info(...)`. Nothing leaves the server. Ideal for local development. |
| `pusher` | `PusherBroadcaster`  | Sends each channel's event to Pusher Channels using the `pusher/pusher-php-server` SDK. |

The `log` driver simply records what would have gone out:

```php
// LogBroadcaster
public function broadcast(array $channels, $event, array $payload = [])
{
    info("Broadcasting event [$event] on channels: " . implode(', ', $channels), $payload);
}
```

The `pusher` driver triggers per channel:

```php
// PusherBroadcaster (constructed from config('broadcasting.connections.pusher'))
public function broadcast(array $channels, $event, array $payload = [])
{
    foreach ($channels as $channel) {
        $this->pusher->trigger($channel, $event, $payload);
    }
}
```

> **Only `log` and `pusher` are wired.** The source tree also contains `RedisBroadcastDriver` and `WebSocketBroadcastDriver`, but they are **not** registered in the manager's `resolve()` switch, so `config('broadcasting.default')` cannot select them. The WebSocket driver is explicitly marked *"very incompleted and should not be used."* Treat both as scaffolding, not shipping features. For a real self-hosted WebSocket path, use `atom-reverb` (section 6).

Requesting any other driver name throws:

```
InvalidArgumentException: Unsupported broadcast driver [redis]
```

---

### 4. **Configuration**

The manager reads its settings from a `broadcasting` config namespace:

- `config('broadcasting.default')` — the driver name (`log` or `pusher`).
- `config('broadcasting.connections.pusher')` — the Pusher credentials array (`key`, `secret`, `app_id`, `cluster`).

A `config/broadcasting.php` is **not** shipped by the framework, so create one in your application. Read from `env()` inside config files (never call `config()` from within a config file):

```php
<?php // config/broadcasting.php

return [
    'default' => env('BROADCAST_DRIVER', 'log'),

    'connections' => [
        'pusher' => [
            'key'     => env('PUSHER_APP_KEY'),
            'secret'  => env('PUSHER_APP_SECRET'),
            'app_id'  => env('PUSHER_APP_ID'),
            'cluster' => env('PUSHER_APP_CLUSTER', 'mt1'),
        ],
    ],
];
```

Keep `BROADCAST_DRIVER=log` in local `.env` and switch to `pusher` in production.

---

### 5. **Channels and authorization (`routes/channels.php`)**

Private channels need an authorization callback so only permitted users can subscribe. Register callbacks with the static `Broadcast::channel()` method, conventionally in a `routes/channels.php` file:

```php
<?php // routes/channels.php

use Eyika\Atom\Framework\Foundation\Broadcasting\BroadcastManager;

BroadcastManager::channel('orders.{orderId}', function ($user, $orderId) {
    // Return truthy to authorize the subscription.
    return $user && $user->id === Order::find($orderId)?->user_id;
});
```

The `{orderId}` placeholder is extracted from the channel name and passed to your callback. When a subscription is authenticated, `BroadcastManager::authenticate($user, $channel)` matches the requested channel against registered patterns and invokes the first matching callback.

This file is loaded by a **`BroadcastServiceProvider`** in your application, which also binds the manager into the container. A typical provider (as used in fx-data-server):

```php
<?php

namespace App\Providers;

use Eyika\Atom\Framework\Foundation\Broadcasting\BroadcastManager;
use Eyika\Atom\Framework\Foundation\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton('broadcast.manager', fn ($app) => new BroadcastManager($app));
        $this->app->instance('broadcast.manager', $this->app->make(BroadcastManager::class));
    }

    public function boot(): void
    {
        if (file_exists($path = base_path('routes/channels.php'))) {
            require $path;   // load channel authorization callbacks
        }
    }
}
```

Register this provider in your app's provider list so the `broadcast.manager` binding (which the `Broadcast` facade resolves) exists and `routes/channels.php` is loaded on boot.

---

### 6. **Self-hosted WebSockets with `atom-reverb`**

`eyika/atom-reverb` is a **separate composer package** — a lightweight, dependency-free WebSocket broadcast server for Atom, in the spirit of Laravel Reverb. It uses PHP's own `stream_socket_server` + `stream_select` (no Ratchet, Swoole, ReactPHP, or Redis), which makes it a good self-hosted alternative to Pusher.

Install it and let package auto-discovery register its provider:

```bash
composer require eyika/atom-reverb
```

The `ReverbServiceProvider` is auto-discovered (via `extra.atom.providers`) and registers the `reverb:start` command, its own `Broadcast` facade / `broadcast()` helper, and a listener that auto-forwards `ShouldBroadcast` events. Start the server on two ports — one for browsers, one for the app to POST events into:

```bash
php console reverb:start --host=127.0.0.1 --ws-port=8091 --ingest-port=8092
```

Broadcast from your app explicitly or via an event:

```php
use Eyika\Atom\Reverb\Support\Broadcast;

Broadcast::send('orders', 'OrderShipped', ['id' => 42]);
// or the package helper:
broadcast('orders', 'OrderShipped', ['id' => 42]);
```

Reverb has its **own** namespaces (`Eyika\Atom\Reverb\...`) and its own `ShouldBroadcast` contract (with `broadcastOn()`, `broadcastAs()`, `broadcastWith()`) that is richer than the framework's single-method contract. It is a self-contained package — you use *either* the framework's `BroadcastManager` (log/pusher) *or* Reverb, not both for the same event flow. Connect from the browser with a plain `WebSocket`:

```js
const ws = new WebSocket('ws://127.0.0.1:8091');
ws.onopen = () => ws.send(JSON.stringify({ event: 'subscribe', data: { channel: 'orders' } }));
ws.onmessage = (e) => {
  const { event, channel, data } = JSON.parse(e.data);
  console.log(event, channel, data);
};
```

See the package's own README for the full protocol and architecture.

---

### 7. **Summary**

- Implement `ShouldBroadcast` with `broadcastOn(): array`; dispatch via `event(...)` and the dispatcher forwards it automatically.
- The delivered event name is the class name and the payload is the event's **public** properties.
- Or publish directly with `Broadcast::broadcast([...], $event, [...])` / the `broadcast()` helper.
- Two drivers are wired: **`log`** (dev) and **`pusher`** (prod). Redis/WebSocket drivers exist in the tree but are **not** wired.
- Configure via a `config/broadcasting.php` you create; authorize private channels in `routes/channels.php`, loaded by a `BroadcastServiceProvider`.
- For self-hosted WebSockets without Pusher, add the separate `eyika/atom-reverb` package.
