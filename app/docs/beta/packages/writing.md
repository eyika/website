# Writing a Package

An Atom package is just a Composer package that happens to know how to introduce itself to the framework. You write a normal `composer.json`, add one `ServiceProvider` subclass, declare it under `extra.atom.providers`, and the moment someone `composer require`s your package, its provider is discovered and booted automatically — no manual edit to `config/app.php` required.

This page walks through the whole shape of a package: the `composer.json` structure, the service provider itself, and exactly how auto-discovery resolves your provider at boot.

## Table of Contents

- [Anatomy of a Package](#anatomy-of-a-package)
- [composer.json](#composerjson)
- [The Service Provider](#the-service-provider)
- [The `extra.atom` Manifest Format](#the-extraatom-manifest-format)
- [How Discovery Works](#how-discovery-works)
- [A Minimal Package Skeleton](#a-minimal-package-skeleton)
- [Exposing a Facade](#exposing-a-facade)
- [Package-Development Helpers](#package-development-helpers)
- [Deferred Providers](#deferred-providers)
- [Developing Against a Local Copy](#developing-against-a-local-copy)
- [What's Next?](#whats-next)

## Anatomy of a Package

At minimum, a package needs:

```
acme/widget/
├── composer.json
└── src/
    └── WidgetServiceProvider.php
```

Everything else — config files, migrations, views, routes, commands — is optional, and each has a dedicated `ServiceProvider` helper method for registering it (covered below).

## composer.json

Your package's `composer.json` needs a PSR-4 autoload map for its own namespace, and — this is the part that makes it an *Atom* package — an `extra.atom` block naming the provider class(es) to auto-load:

```json
{
    "name": "acme/widget",
    "description": "A Widget package for Atom.",
    "type": "library",
    "require": {
        "php": "^8.1",
        "eyika/atom-framework": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Acme\\Widget\\": "src/"
        }
    },
    "extra": {
        "atom": {
            "providers": [
                "Acme\\Widget\\WidgetServiceProvider"
            ]
        }
    }
}
```

A few things worth calling out:

- `require` should depend on `eyika/atom-framework`, not on a host application — your package should work in *any* Atom app.
- `providers` is a list, so a package can register more than one provider (e.g. a core provider plus a console-only provider).
- The `extra.atom` block is read from **installed packages** — it has no effect on the package's own repo until it's actually required into an app's `vendor/`.

## The Service Provider

Every provider extends the abstract `Eyika\Atom\Framework\Foundation\ServiceProvider` and implements `register()`:

```php
<?php

namespace Acme\Widget;

use Eyika\Atom\Framework\Foundation\ServiceProvider;

class WidgetServiceProvider extends ServiceProvider
{
    /**
     * Register container bindings.
     */
    public function register(): void
    {
        $this->app->singleton('widget', function ($app) {
            return new WidgetManager($app->make('config')->get('widget', []));
        });
    }

    /**
     * Bootstrap services — register routes/config/migrations/commands/views here.
     */
    public function boot(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/widget.php', 'widget');
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'widget');

        $this->publishes([
            __DIR__ . '/../config/widget.php' => config_path('widget.php'),
        ], 'config');
    }
}
```

This is exactly what `php artisan make:provider` scaffolds for an application provider (`app/Providers/`) — a package provider is the same class shape, just shipped inside your own package instead of the host app. `register()` is abstract and **must** be implemented (even if empty); `boot()` is optional and defaults to a no-op.

> `register()` should only bind things into the container. Don't resolve other services from `$this->app` there — other providers may not have registered yet. Do that kind of work in `boot()`, which runs after every provider's `register()` has run.

## The `extra.atom` Manifest Format

The full shape Atom looks for in a package's `composer.json` is:

```json
{
    "extra": {
        "atom": {
            "providers": [
                "Acme\\Widget\\WidgetServiceProvider"
            ],
            "aliases": {
                "Widget": "Acme\\Widget\\Facades\\Widget"
            }
        }
    }
}
```

- **`providers`** — an array of fully-qualified `ServiceProvider` class-strings. This is the part that's actually wired up automatically: every class listed here is merged into the app's provider list and registered/booted at boot (see below).
- **`aliases`** — an `alias => Facade class` map. `PackageManifest::aliases()` compiles this block from every installed package (later packages override earlier ones on a name collision) and it's available to read, but nothing in the boot sequence auto-binds it into the container yet. In practice, ship your facade binding from the provider itself with `registerFacades()` (see [Exposing a Facade](#exposing-a-facade)) rather than relying on this block alone.

Both keys are optional and coerced to arrays if omitted or malformed, so a package that only ships a provider can skip `aliases` entirely, as in the [minimal skeleton](#a-minimal-package-skeleton) above.

## How Discovery Works

1. **`composer require acme/widget`** drops the package into `vendor/`, and Composer writes its `extra.atom` block into `vendor/composer/installed.json` along with every other installed package's metadata.
2. **`php artisan package:discover`** — normally wired into Composer's `post-install-cmd` / `post-update-cmd` scripts — instantiates `Eyika\Atom\Framework\Foundation\PackageManifest`, reads `installed.json`, and for every package that declares an `extra.atom` block, records its `providers`/`aliases` keyed by package name. It writes the compiled result to `bootstrap/cache/packages.php` and prints what it found:

   ```
   Discovered 2 package(s), 3 provider(s): /path/to/app/bootstrap/cache/packages.php
     - acme/widget
     - acme/tools
   ```

3. **At boot**, `Application::registerProviders()` merges `config('app.providers')` (your app's own explicit list) with `PackageManifest::providers()` — the flattened, deduplicated list of every auto-discovered provider — and registers/boots each one exactly once (a `keyExists()` guard prevents double registration if a package is *also* listed by hand in `config/app.php`).

If `bootstrap/cache/packages.php` doesn't exist yet (you haven't run `package:discover`), `PackageManifest` falls back to parsing `installed.json` directly, in-memory, once per process — so discovery still works without the cache, just slower. Delete the cache file (or call `PackageManifest::deleteManifest()`) any time you need it rebuilt from scratch; running `package:discover` again regenerates it.

> Always run `php artisan package:discover` after installing/updating a package that ships an `extra.atom` block, and commit `bootstrap/cache/packages.php` the same way you'd treat any other build artifact for your deploy process. In development this usually runs for you as a Composer script hook.

## A Minimal Package Skeleton

Putting it together, the smallest possible working Atom package is two files:

**`composer.json`**

```json
{
    "name": "acme/widget",
    "type": "library",
    "require": {
        "php": "^8.1",
        "eyika/atom-framework": "^1.0"
    },
    "autoload": {
        "psr-4": {
            "Acme\\Widget\\": "src/"
        }
    },
    "extra": {
        "atom": {
            "providers": ["Acme\\Widget\\WidgetServiceProvider"]
        }
    }
}
```

**`src/WidgetServiceProvider.php`**

```php
<?php

namespace Acme\Widget;

use Eyika\Atom\Framework\Foundation\ServiceProvider;

class WidgetServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(WidgetManager::class, fn () => new WidgetManager());
    }
}
```

Require it, run discovery, and `WidgetManager::class` is resolvable from the container anywhere in the host app:

```bash
composer require acme/widget
php artisan package:discover
```

```php
$widget = app(\Acme\Widget\WidgetManager::class);
```

See [Service Container](../advanced/service-container) for the full range of `bind`/`singleton`/`instance` registration options available inside `register()`.

## Exposing a Facade

If you want consumers to call your package statically (`Widget::doThing()`), ship a `Facade` subclass and register it from your provider with `registerFacades()`, which the framework reads back during `registerProviders()`:

```php
<?php

namespace Acme\Widget\Facades;

use Eyika\Atom\Framework\Support\Facade\Facade;

class Widget extends Facade
{
    protected static function getFacadeAccessor()
    {
        return 'widget';
    }
}
```

```php
public function register(): void
{
    $this->app->singleton('widget', fn ($app) => new WidgetManager());

    $this->registerFacades([
        'Widget' => Facades\Widget::class,
    ]);
}
```

`registerFacades()` just accumulates into the provider's `$facades` array; `getFacades()` returns it. During boot, `Application::registerProviders()` reads `getFacades()` back off every registered provider and binds each alias into the container. `getFacadeAccessor()` is what actually resolves the facade's underlying instance (here, the `'widget'` singleton bound in `register()`) whenever a static method is called on `Widget::`.

## Package-Development Helpers

`ServiceProvider` ships a set of protected helpers for exactly this kind of package wiring — call them from `boot()`:

| Method | Purpose |
|---|---|
| `loadRoutesFrom(string $path)` | `require`s a routes file so its `Route::*` calls join the table before dispatch. |
| `mergeConfigFrom(string $path, string $key)` | Merges your package's default config array under `$key`, with the app's own `config/*.php` (if any) taking precedence over your defaults. Silently no-ops if `$path` doesn't exist or doesn't return an array. |
| `loadMigrationsFrom(string\|array $paths)` | Registers one or more migration directories, picked up alongside the app's own by `php artisan migrate` (see [Migrations](../database/migrations)). |
| `loadViewsFrom(string\|array $paths, string $namespace)` | Registers a namespaced view directory so `view('widget::dashboard')` resolves (see [Views](../views)). |
| `loadTranslationsFrom(string $path, string $namespace)` | Registers a namespace → path hint for translations. Recorded today; there's no consuming translator subsystem yet, so treat this as forward-compatible. |
| `commands(array\|string $commands)` | Registers console command class-strings that the console kernel loads in addition to the framework's and app's own (see [Console Commands](../console-commands)). |
| `publishes(array $paths, string $tag = 'default')` | Registers source → destination file pairs under a tag, copyable into the host app via `php artisan vendor:publish --tag=your-tag` (or `--provider=Acme\Widget\WidgetServiceProvider`, optionally with `--force` to overwrite). Repeated calls with the same tag accumulate. |

All of these except `loadRoutesFrom()` and `mergeConfigFrom()` write into `static` registries on `ServiceProvider` itself (append-only, deduplicated), so every package's contributions accumulate across a single boot regardless of which provider added them.

```php
public function boot(): void
{
    $this->loadRoutesFrom(__DIR__ . '/../routes/web.php');
    $this->mergeConfigFrom(__DIR__ . '/../config/widget.php', 'widget');
    $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    $this->loadViewsFrom(__DIR__ . '/../resources/views', 'widget');
    $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'widget');
    $this->commands([\Acme\Widget\Console\Commands\SyncWidgets::class]);

    $this->publishes([
        __DIR__ . '/../config/widget.php' => config_path('widget.php'),
    ], 'config');

    $this->publishes([
        __DIR__ . '/../resources/views' => resource_path('views/vendor/widget'),
    ], 'views');
}
```

## Deferred Providers

If your provider only exists to bind one or two rarely-used services, implement `DeferrableProvider` so it's skipped at boot and only registered the first time one of its services is actually resolved from the container:

```php
<?php

namespace Acme\Widget;

use Eyika\Atom\Framework\Foundation\Contracts\DeferrableProvider;
use Eyika\Atom\Framework\Foundation\ServiceProvider;

class WidgetServiceProvider extends ServiceProvider implements DeferrableProvider
{
    public function register(): void
    {
        $this->app->singleton('widget', fn () => new WidgetManager());
    }

    public function provides(): array
    {
        return ['widget'];
    }
}
```

`Application::registerProviders()` checks `is_a($provider, DeferrableProvider::class, true)` for every discovered provider (auto-discovered *and* explicitly listed in `config('app.providers')` alike) and, if it implements the interface, records which services it `provides()` instead of instantiating it immediately. The first `app()->make('widget')` (or any other provided service) triggers `register()` **and** `boot()` for that provider on the spot, then drops its deferred entries so it isn't triggered again. This is worth it under persistent workers where avoiding unnecessary per-request boot cost matters — but only for providers whose services are genuinely optional per-request.

## Developing Against a Local Copy

While building a package you don't want to publish to Packagist yet, point the host app's Composer at your local checkout with a `path` repository:

```json
{
    "repositories": [
        { "type": "path", "url": "../acme-widget" }
    ]
}
```

```bash
composer require acme/widget:@dev
php artisan package:discover
```

Composer symlinks the package into `vendor/acme/widget`, so edits to your package source are picked up immediately — you only need to re-run `package:discover` if you change the `extra.atom` block itself (add/remove a provider), not for changes inside an already-discovered provider's `register()`/`boot()`.

## What's Next?

- [Official Packages](index) — real packages built this exact way, as worked examples.
- [Service Providers](../extending/service-providers) — a deeper look at provider internals and lifecycle.
- [Service Container](../advanced/service-container) — binding styles available inside `register()`.
- [Configuration → Package Auto-Discovery](../configuration#package-auto-discovery) — the app-side view of this same mechanism.
- [Console Commands](../console-commands) — writing the commands you register with `commands()`.
- [Migrations](../database/migrations) — what happens to paths registered with `loadMigrationsFrom()`.
