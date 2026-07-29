# Service Providers

Service providers are the bootstrapping layer of an Atom application. Every subsystem you rely on — routing, events, caching, views, the database — is wired up by a provider, and your own application/package services should be too. This page goes deeper than the [Service Container](../advanced/service-container) guide's brief mention of providers: it covers the `register()`/`boot()` lifecycle in detail, how to bind services correctly inside a provider, how providers get discovered and loaded by the `Application`, and a complete worked example.

## Table of Contents

- [The Base Class](#the-base-class)
- [register() vs boot()](#register-vs-boot)
  - [Why the Split Matters](#why-the-split-matters)
- [Binding Into the Container](#binding-into-the-container)
- [A Complete Example](#a-complete-example)
- [Registering the Provider](#registering-the-provider)
  - [Scaffolding With make:provider](#scaffolding-with-makeprovider)
- [How the Application Loads and Boots Providers](#how-the-application-loads-and-boots-providers)
  - [Provider Merge and De-duplication](#provider-merge-and-de-duplication)
  - [Registration Phase](#registration-phase)
  - [Boot Phase](#boot-phase)
  - [Where This Runs From](#where-this-runs-from)
- [Deferred Providers](#deferred-providers)
- [Registering Facade Aliases From a Provider](#registering-facade-aliases-from-a-provider)
- [Package-Development Helpers](#package-development-helpers)
- [Gotchas](#gotchas)
- [Conclusion](#conclusion)

---

## The Base Class

Every service provider extends `Eyika\Atom\Framework\Foundation\ServiceProvider`:

```php
namespace Eyika\Atom\Framework\Foundation;

abstract class ServiceProvider
{
    public function __construct(ApplicationInterface $app) { ... }

    abstract public function register(): void;

    public function boot(): void
    {
        // no-op by default — override if you need it
    }
}
```

The constructor is called for you with the `Application` instance, stored on `$this->app`, so any binding or resolving you do inside `register()`/`boot()` goes through `$this->app`. `register()` is `abstract` — every provider **must** implement it, even if the body is empty. `boot()` is optional; the base class already provides a no-op implementation, so you only override it if the provider needs to do something after all providers have registered.

## register() vs boot()

- **`register()`** — bind services into the container. This is the *only* place you should put container bindings (`bind()`, `singleton()`, `instance()`, `scoped()`, `alias()`, `extend()`, `tag()`). Do not resolve (`make()`) services here that another provider might not have registered yet.
- **`boot()`** — do work that depends on *other* providers' bindings already being registered: attach event listeners, load routes/views/migrations/translations, register commands, publish assets, or resolve and configure a service now that the whole container is populated.

```php
class ReportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Only bind here.
        $this->app->singleton(ReportBuilder::class, fn ($app) => new ReportBuilder(
            $app->make('db.connection')
        ));
    }

    public function boot(): void
    {
        // Safe to resolve services and wire them up to other subsystems here.
        Event::listen('order.closed', function ($order) {
            $this->app->make(ReportBuilder::class)->recordClose($order);
        });
    }
}
```

### Why the Split Matters

`Application::registerProviders()` runs `register()` on **every** non-deferred provider first, and only calls `boot()` on any of them *after the entire loop is finished* (`bootProviders()` runs after the registration loop, not interleaved with it — see [How the Application Loads and Boots Providers](#how-the-application-loads-and-boots-providers)). That ordering guarantee is exactly why the split exists:

- Inside `register()`, another provider later in `config('app.providers')` may not have bound its service yet — resolving it would fail or resolve the wrong (auto-reflected) thing.
- Inside `boot()`, **every** provider has already run `register()`, so it's safe to `$this->app->make(...)` a binding that a different provider owns, listen for events another provider dispatches, or otherwise depend on the fully-wired container.

## Binding Into the Container

A provider's `register()` method is where you call the container's binding methods on `$this->app`. Atom's container gives you three flavors (full detail, including `alias()`, `extend()`, `tag()`, and `scoped()`, is in [Service Container](../advanced/service-container)):

```php
public function register(): void
{
    // Transient: a fresh instance every time it's resolved
    $this->app->bind(UserRepository::class, function ($app) {
        return new UserRepository($app->make('db.connection'));
    });

    // Singleton: resolver runs once, memoized for the app's lifetime
    $this->app->singleton(Logger::class, function ($app) {
        return new Logger(config('logging.level'));
    });

    // Instance: register an already-constructed object as-is
    $this->app->instance('cache', new FileCache(storage_path('cache')));
}
```

> `bind()` in Atom is **transient**, not shared — two `make()` calls return two different objects. Reach for `singleton()` (or `instance()` if you already have the object) whenever the service should be shared.

The resolver closure receives the container itself (`$app`), so you can pull in the service's own dependencies via `$app->make(...)` rather than hardcoding `new` on concrete classes deep inside the closure.

## A Complete Example

A provider that binds a service in `register()` and boots something that depends on it — an audit logger that's bound as a singleton, then wired to the event dispatcher once every provider has registered:

```php
<?php

namespace App\Providers;

use App\Services\AuditLogger;
use Eyika\Atom\Framework\Foundation\ServiceProvider;
use Eyika\Atom\Framework\Support\Facade\Event;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * Register container bindings.
     */
    public function register(): void
    {
        $this->app->singleton(AuditLogger::class, function ($app) {
            return new AuditLogger(
                storage_path('logs/audit.log'),
                config('app.env')
            );
        });
    }

    /**
     * Bootstrap services — register routes/config/migrations/commands/views here.
     */
    public function boot(): void
    {
        $logger = $this->app->make(AuditLogger::class);

        // Every provider has registered by now, so listening for events other
        // providers/subsystems dispatch is safe.
        Event::listen('user.login', fn ($user) => $logger->write("login: {$user->email}"));
        Event::listen('user.logout', fn ($user) => $logger->write("logout: {$user->email}"));

        // Package-development helper — load a routes file whose Route::* calls
        // run immediately (see Package-Development Helpers below).
        $this->loadRoutesFrom(__DIR__ . '/../../routes/audit.php');
    }
}
```

This is exactly the shape `make:provider` scaffolds for you (see below) — a `register()` for bindings, a `boot()` for everything that depends on the rest of the container being wired up.

## Registering the Provider

A provider class does nothing on its own — the `Application` only instantiates, registers, and boots providers that are listed in `config('app.providers')`:

```php
// config/app.php
'providers' => [
    /*
     * Default service providers.
     */
    \App\Providers\CacheServiceProvider::class,
    \App\Providers\RouteServiceProvider::class,
    \App\Providers\ConsoleServiceProvider::class,
    \App\Providers\EventServiceProvider::class,
    \App\Providers\ViewServiceProvider::class,
    \App\Providers\DatabaseServiceProvider::class,

    /*
     * Application Service Providers...
     */
    \App\Providers\AppServiceProvider::class,
    \App\Providers\AuditServiceProvider::class,
],
```

This is a plain array of class-strings, own by your app — see [Configuration](../configuration#service-providers) for the full `config/app.php` layout. There's no separate "kernel" registration step; being listed in this array is both how a provider is discovered *and* the order it registers in (order matters for `register()`, since a later provider can safely depend on an earlier one only once `boot()` runs — see [above](#why-the-split-matters)).

Atom packages don't need to be listed here at all — a package that declares an `extra.atom.providers` block in its own `composer.json` is auto-discovered and merged in for you. See [Package Auto-Discovery](../configuration#package-auto-discovery) and [Writing Packages](../packages/writing) if you're authoring a package rather than an app provider.

### Scaffolding With make:provider

```bash
php artisan make:provider AuditServiceProvider
```

This writes `app/Providers/AuditServiceProvider.php` from the framework's stub — an empty `register()` and `boot()` extending `ServiceProvider`, ready to fill in. It does **not** add the class to `config('app.providers')` for you; add the line yourself as shown above.

## How the Application Loads and Boots Providers

`Application::registerProviders()` is the single entry point that turns your `config('app.providers')` list into live, booted providers. It runs in three steps:

### Provider Merge and De-duplication

```php
$providers = array_values(array_unique(array_merge(
    config('app.providers', []),
    (new PackageManifest())->providers()
)));
```

Your app's `providers` array is merged with every provider auto-discovered from installed packages (`PackageManifest`), then de-duplicated. Each provider class-string is also checked against `loadedProviders()->keyExists($provider)` inside the loop, so the same provider is never registered twice even if it appears in both places.

### Registration Phase

For each provider class in that merged list, in order:

1. If the provider implements `DeferrableProvider`, it is **not** registered now — see [Deferred Providers](#deferred-providers) below.
2. Otherwise, the provider is instantiated (`new $provider($this)`) and `register()` is called on it.
3. The instance is stored via `loadProvider($provider, $instance)`, making it retrievable from `loadedProviders()`.
4. Any facades the provider declared with `registerFacades()` are bound into the container automatically (see [below](#registering-facade-aliases-from-a-provider)).

This entire loop — every non-deferred provider's `register()` — completes in full before boot starts.

### Boot Phase

Once every provider has registered, `registerProviders()` calls `bootProviders()`, which iterates `loadedProviders()` and calls `boot()` on each one:

```php
protected function bootProviders(): void
{
    $this->loadedProviders()->each(function (&$index, ServiceProvider &$instance) {
        $instance->boot();
    });
}
```

This is the mechanism behind the [register() vs boot() ordering guarantee](#why-the-split-matters): because this loop runs strictly after the registration loop above, every provider's `boot()` can safely resolve any binding any other provider registered, regardless of list order.

### Where This Runs From

`registerProviders()` is called once, near the start of each entry point's lifecycle — you don't call it yourself:

- `Eyika\Atom\Framework\Http\Server` — for HTTP requests.
- `Eyika\Atom\Framework\Foundation\ConsoleKernel` — for `php artisan` commands.
- `Eyika\Atom\Framework\Support\Testing\TestCase` — for tests that boot a full application.

## Deferred Providers

A provider that binds a service which is expensive to construct but rarely used can implement `DeferrableProvider` to skip registration/boot on every request and instead register lazily the first time one of its services is actually resolved:

```php
use Eyika\Atom\Framework\Foundation\Contracts\DeferrableProvider;
use Eyika\Atom\Framework\Foundation\ServiceProvider;

class PdfServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton(PdfGenerator::class, fn ($app) => new PdfGenerator());
    }

    public function provides(): array
    {
        return [PdfGenerator::class];
    }
}
```

During `registerProviders()`, a deferred provider is only instantiated far enough to call `provides()` — the returned service names are recorded in a `service => providerClass` map, and `register()`/`boot()` are **not** called yet. The first time any of those services is resolved via `make()`, the container's deferred hook constructs the provider for real, calls `register()`, then immediately calls `boot()` on it (both run together at that point, not on the app's normal boot pass), and removes it from the deferred map so it only ever registers once. See [Deferred providers](../advanced/service-container#9-service-container-advanced-usage) in the Service Container guide for the container-side half of this.

## Registering Facade Aliases From a Provider

A provider can bind facade aliases alongside its services with `registerFacades()`:

```php
public function register(): void
{
    $this->app->singleton(PaymentGateway::class, fn () => new StripePaymentGateway());

    $this->registerFacades([
        'Payment' => \App\Facades\Payment::class,
    ]);
}
```

`Application::registerProviders()` reads `getFacades()` back off the provider right after calling `register()` and binds each alias into the container as an `instance()` (`$this->instance($alias, new $class)`), for both normal and deferred providers.

## Package-Development Helpers

`ServiceProvider` also exposes a set of protected helpers meant for package authors, typically called from `boot()`: `loadRoutesFrom()`, `mergeConfigFrom()`, `loadMigrationsFrom()`, `loadViewsFrom()`, `loadTranslationsFrom()`, `commands()`, and `publishes()`/`getPublishables()` for `php artisan vendor:publish`. An application's own providers can use them too (the `loadRoutesFrom()` call in the [complete example](#a-complete-example) above is a normal, non-package use). Full coverage of each helper — including how the migrator, view resolver, and console kernel consume what they register — lives in [Writing Packages](../packages/writing).

## Gotchas

- **Don't resolve services in `register()`** that another provider might own — the loop order in `config('app.providers')` doesn't guarantee that provider has run yet. Save cross-provider dependencies for `boot()`.
- **`bind()` is transient in Atom**, unlike some other frameworks' default container behavior. If you bind a service in `register()` and expect the same instance back everywhere, use `singleton()` (or `instance()`), not `bind()`.
- **`make:provider` doesn't register itself.** You still have to add the generated class to `config('app.providers')` by hand — auto-discovery only applies to providers shipped inside an installed package's `composer.json`.
- **Deferred providers only defer application providers you mark yourself** — implement `DeferrableProvider` and `provides()` explicitly; nothing infers deferability from what a provider binds.
- **A provider's `boot()` doesn't run at all until `registerProviders()`'s registration loop is completely finished** — so even non-deferred providers can't observe each other's `boot()` side effects from inside `register()`.

## Conclusion

A service provider is just a plain class with a `register()` you must implement and a `boot()` you may override, listed in `config('app.providers')`. `register()` should do nothing but bind; `boot()` is where it's safe to resolve other services, listen for events, and wire your bound service into the rest of the app — because by the time any provider's `boot()` runs, every provider in the list has already finished registering. From there, `bind()`/`singleton()`/`instance()`/`scoped()` on `$this->app` are the same container primitives covered in depth in the [Service Container](../advanced/service-container) guide, and `DeferrableProvider` lets you skip the cost of registering a rarely-used service until it's actually needed.
