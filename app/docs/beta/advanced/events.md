## Event System

An event-driven architecture helps decouple different parts of your application by allowing them to communicate via events. The event system is an essential feature in modern PHP frameworks that facilitates the broadcasting, listening, and handling of events. It enhances flexibility by decoupling the logic of event dispatch from the logic of event handling.

### 1. **Event System Overview**
   The event system in Atom allows different parts of the application to listen for and react to specific events. Events can be dispatched, and listeners can respond to those events, executing their respective actions.

   **Key Concepts:**
   - **Event:** A specific action or occurrence in the application, identified either by a string name (`'user.registered'`) or by an event object's class.
   - **Listener:** A closure, class, or `'Class@method'` reference that responds to an event.
   - **Dispatcher:** The `Eyika\Atom\Framework\Foundation\Event\Dispatcher`, bound in the container as `'events'`, is responsible for firing events and invoking listeners.
   - **Subscriber:** A specialized class that registers listeners for multiple events at once.

### 2. **Event Dispatching**
   Events are dispatched when an action occurs in your application. For example, an event could be triggered when a user registers or when a new record is saved to the database.

   Atom supports two flavours of events:

   **String events with a payload** — the simplest form. The payload is passed as an array of arguments to the listeners:
   ```php
   // Using the event() helper
   event('user.registered', [$user]);

   // Or resolving the dispatcher directly
   app('events')->dispatch('user.registered', [$user]);
   ```

   **Object events** — the event's class name becomes the event name, and the object itself is the single payload argument passed to listeners:
   ```php
   event(new UserRegistered($user));
   ```

   The `event()` helper signature is `event($event, $payload = [], $halt = false)`. You may also use the `Event` facade:
   ```php
   use Eyika\Atom\Framework\Support\Facade\Event;

   Event::dispatch('user.registered', [$user]);
   ```

### 3. **Defining an Event**
   For object events, an event is simply a plain class that holds data related to the occurrence. It encapsulates information that listeners need to handle the event — no interface or base class is required.

   **Example of an Event:**
   ```php
   class UserRegistered
   {
       public $user;

       public function __construct(User $user)
       {
           $this->user = $user;
       }
   }
   ```

   The `UserRegistered` event class holds the `User` object that contains all the necessary data to be used by the listeners. Scaffold one with `php artisan make:event UserRegistered`.

### 4. **Listening for Events**
   Listeners respond to specific events. A listener may be a closure, an invokable class, a class with a `handle()` method, an `[$object, 'method']` pair, or a `'Class@method'` string.

   **Registering a listener:**
   ```php
   use Eyika\Atom\Framework\Support\Facade\Event;

   // Closure listener
   Event::listen('user.registered', function (User $user) {
       // ...
   });

   // Class listener (resolves the class and calls handle() or __invoke())
   Event::listen(UserRegistered::class, SendWelcomeEmail::class);

   // "Class@method" listener
   Event::listen(UserRegistered::class, 'App\Listeners\SendWelcomeEmail@handle');
   ```

   **Defining a listener class:**
   ```php
   class SendWelcomeEmail
   {
       public function handle(UserRegistered $event)
       {
           // Send a welcome email to the user
           Mail::to($event->user->email)->send(new WelcomeEmail($event->user));
       }
   }
   ```

   When a listener is given as a class-string, the dispatcher instantiates it and calls its `handle()` method if present, otherwise `__invoke()`. Scaffold one with `php artisan make:listener SendWelcomeEmail`.

### 5. **Wildcard Listeners**
   Listeners may subscribe to a wildcard pattern, where `*` matches any characters. This is useful for catching a whole family of events with one listener — including model events (see below).

   ```php
   use Eyika\Atom\Framework\Support\Facade\Event;

   // React to every "model.*" event
   Event::listen('model.*', function ($payload) {
       // ...
   });

   // React to absolutely everything
   Event::listen('*', function ($payload) {
       // ...
   });
   ```

### 6. **Halting, Inspecting & Forgetting**
   The dispatcher exposes several methods for finer control over propagation and registration:

   - **`until($event, $payload = [])`** — dispatch and return the first **non-null** listener response, stopping propagation. Useful for "before" hooks / veto checks.
   - **A listener returning `false`** halts propagation of the remaining listeners.
   - **`hasListeners($eventName)`** — returns whether any listener (exact or wildcard) is registered for an event.
   - **`forget($event)`** — remove all listeners for an exact event or wildcard pattern.

   ```php
   use Eyika\Atom\Framework\Support\Facade\Event;

   // Stop at the first listener that returns a non-null value
   $result = Event::until('order.validating', [$order]);

   if (Event::hasListeners('order.shipped')) {
       // ...
   }

   Event::forget('order.shipped');
   ```

   The `event()` helper also accepts a third `$halt` argument: `event('order.validating', [$order], true)` behaves like `until()`.

### 7. **Event Subscribers**
   An event subscriber is a class that registers listeners for multiple events. Instead of registering each listener individually, the subscriber's `subscribe()` method receives the dispatcher and wires up its own listeners.

   **Defining an Event Subscriber:**
   ```php
   use Eyika\Atom\Framework\Foundation\Event\Dispatcher;

   class UserEventSubscriber
   {
       public function subscribe(Dispatcher $events): void
       {
           $events->listen(
               UserRegistered::class,
               SendWelcomeEmail::class
           );

           $events->listen(
               UserLoggedIn::class,
               LogUserLogin::class
           );
       }
   }
   ```

   **Registering a Subscriber:**
   ```php
   use Eyika\Atom\Framework\Support\Facade\Event;

   Event::subscribe(UserEventSubscriber::class);
   ```

   You can pass either a class-string or an already-constructed subscriber instance.

### 8. **Event Service Provider**
   The `EventServiceProvider` is the central place to register the application's event → listener mappings. It exposes a `$listen` map that is registered on boot via `registerListeners()`.

   **Example of the Event Service Provider:**
   ```php
   namespace App\Providers;

   use Eyika\Atom\Framework\Foundation\Event\Dispatcher;
   use Eyika\Atom\Framework\Foundation\ServiceProvider;

   class EventServiceProvider extends ServiceProvider
   {
       /**
        * The event → listener mappings for the application.
        *
        * @var array<string, array<int, class-string>>
        */
       protected array $listen = [
           \App\Events\UserRegistered::class => [
               \App\Listeners\SendWelcomeEmail::class,
               \App\Listeners\LogUserRegistration::class,
           ],
       ];

       public function register(): void
       {
           $this->app->singleton(Dispatcher::class, fn () => new Dispatcher());
           $this->app->instance('events', $this->app->make(Dispatcher::class));
       }

       public function boot(): void
       {
           $dispatcher = $this->app->make(Dispatcher::class);
           $dispatcher->registerListeners($this->listen);
       }
   }
   ```

   The keys of `$listen` may be object-event class names or string event names, and each maps to one or more listeners. This provider is registered in `config/app.php` like any other.

### 9. **Model Events**
   Atom models fire lifecycle events as records move through their persistence flow. You can hook these to run logic automatically whenever a model is created, updated, saved, deleted, or retrieved.

   The observable model events are:

   `retrieved`, `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted`, `restoring`, `restored`

   Register a callback for a single event directly on the model class. Each callback receives the model instance:
   ```php
   User::creating(function ($user) {
       $user->uuid = Str::uuid();
   });

   User::deleted(function ($user) {
       // clean up related records
   });
   ```

   The "before" events (`creating`, `updating`, `saving`, `deleting`, `restoring`) can **abort the operation** by returning `false` from the callback:
   ```php
   Order::deleting(function ($order) {
       if ($order->isLocked()) {
           return false; // cancels the delete
       }
   });
   ```

### 10. **Model Observers**
   When you find yourself listening to many events on a model, group them into an **observer** — a class whose method names match the model events. Only the methods the observer actually defines are wired up.

   **Defining an observer** (scaffold with `php artisan make:observer UserObserver`):
   ```php
   class UserObserver
   {
       public function creating($user): void
       {
           $user->uuid = Str::uuid();
       }

       public function created($user): void
       {
           // send verification email, etc.
       }

       public function deleted($user): void
       {
           // cleanup
       }
   }
   ```

   **Registering the observer** — typically in a service provider's `boot()`:
   ```php
   use App\Models\User;
   use App\Observers\UserObserver;

   User::observe(UserObserver::class);
   ```

   `observe()` accepts a class-string, an instance, or an array of either, so you can attach several observers to the same model.

### 11. **Event Broadcasting**
   Broadcasting events allows you to send events to the client-side, typically via WebSockets, for real-time communication. An event object that implements the `ShouldBroadcast` interface is automatically broadcast when it is dispatched.

   ```php
   use Eyika\Atom\Framework\Broadcasting\Contracts\ShouldBroadcast;

   class OrderShipped implements ShouldBroadcast
   {
       public $order;

       public function __construct(Order $order)
       {
           $this->order = $order;
       }

       public function broadcastOn()
       {
           return 'orders';
       }
   }
   ```

   When `event(new OrderShipped($order))` is dispatched, the dispatcher broadcasts it on the channel(s) returned by `broadcastOn()` in addition to invoking any local listeners.

### 12. **Event System Best Practices**
   - **Use events to decouple logic:** Dispatch an event when something significant happens, and let listeners handle the related side-effects (email, logging, notifications).
   - **Prefer observers for models:** When a model needs several lifecycle hooks, group them into an observer for better organization.
   - **Use wildcards deliberately:** `'model.*'` and `'*'` listeners are powerful for cross-cutting concerns like auditing, but keep their work light.
   - **Use `until()` for veto flows:** When an event should decide whether an action proceeds, use `until()` (or the `$halt` argument) and have listeners return a value or `false`.
   - **Broadcast sparingly:** Only broadcast critical events to avoid overloading real-time clients.

By utilizing the event system, you can achieve a more modular, maintainable, and responsive application architecture. The event-driven approach lets you build decoupled components and react to significant moments — including a model's entire lifecycle — in a clean and effective manner.
