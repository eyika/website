# Custom Auth Guards

Atom ships three authentication guards — `session`, `token`, and `jwt` — but nothing about the auth layer is hardcoded to them. `Auth::guard($name)` resolves a guard purely from `config('auth')`: a `guards` map tells it which **driver** a named guard uses, and a `driver_classes` map tells it which PHP class implements that driver. To add a new authentication mechanism (an API-key header, a signed cookie scheme, a third-party SSO token, …) you write a class that extends `Authenticator`, register it under a driver name, and point a guard at that driver. No core file changes.

This page covers writing and registering a guard. For role checks and enforcing access once a user is authenticated, see [Authorization](../authorization); for the shipped `session`/`token`/`jwt` guards themselves, see [Security](../advanced/security).

## Table of Contents

- [How Guard Resolution Works](#how-guard-resolution-works)
- [The `config('auth')` Shape](#the-configauth-shape)
  - [`guards`](#guards)
  - [`driver_classes`](#driver_classes)
  - [`providers` and `auth_drivers`](#providers-and-auth_drivers)
- [Writing a Guard](#writing-a-guard)
  - [The `Authenticator` Contract](#the-authenticator-contract)
  - [Helpers Inherited From `Authenticator`](#helpers-inherited-from-authenticator)
- [Worked Example: An API-Key Guard](#worked-example-an-api-key-guard)
- [Registering the Driver Class](#registering-the-driver-class)
- [Selecting the Guard](#selecting-the-guard)
- [Resolving Guards at Runtime](#resolving-guards-at-runtime)
- [Gotchas](#gotchas)
- [Related](#related)

---

## How Guard Resolution Works

Every call that needs the current user — `Auth::user()`, `Auth::check()`, `Auth::attempt()`, middleware that reads `$request->auth_user` — goes through `Auth::guard()`:

```php
public static function guard(string|null $name = null): Authenticator
{
    if ($name && isset(static::$guards[$name])) {
        return static::$guards[$name];
    }
    static::init();
    $name = $name ?? static::$guardName;

    if (!isset(static::$config['guards'][$name])) {
        throw new \InvalidArgumentException("Guard [$name] is not defined.");
    }

    $guardConfig = static::$config['guards'][$name];
    $driverClass = static::resolveDriverClass($guardConfig['driver'], static::$config['driver_classes']);

    return new $driverClass(static::$config, $name);
}
```

Two config lookups drive this:

1. `config('auth.guards.<name>')` must exist, or `Auth::guard('foo')` throws `InvalidArgumentException: Guard [foo] is not defined.`
2. That guard entry's `driver` key must exist in `config('auth.driver_classes')`, or resolution throws `InvalidArgumentException: Driver [foo] is not supported.`

Once both resolve, Atom instantiates your guard class as `new $driverClass(static::$config, $name)` — the **entire** `config('auth')` array (not just the one guard's slice) plus the guard's own name. Your guard's constructor must accept `(array $config, string $guard)` to match `Authenticator::__construct()`.

`Auth::getDefaultGuard()` picks the guard name when none is passed explicitly: `Request::wantsJson() ? 'api' : ($config['defaults']['guard'] ?? 'web')` — set during `Auth::init()`. So a JSON request resolves the `api` guard, everything else resolves `web` (or whatever `auth.defaults.guard` says), unless you call `Auth::guard('name')` explicitly.

---

## The `config('auth')` Shape

A full `config/auth.php`, annotated for what a custom guard needs:

```php
<?php

use App\Http\Guards\ApiKeyGuard;
use Eyika\Atom\Framework\Support\Auth\Drivers\DatabaseDriver;
use Eyika\Atom\Framework\Support\Auth\Drivers\EloquentDriver;
use Eyika\Atom\Framework\Support\Auth\Guards\JwtGuards\JwtGuard;
use Eyika\Atom\Framework\Support\Auth\Guards\SessionGuard;
use Eyika\Atom\Framework\Support\Auth\Guards\TokenGuard;

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],
        'api' => [
            'driver' => 'jwt',
            'provider' => 'users',
        ],
        // A guard for your custom driver:
        'devices' => [
            'driver' => 'api_key',
            'provider' => 'users',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],
    ],

    // Fallback model for the shipped EloquentDriver/DatabaseDriver when a provider omits its
    // own `model` — providers.*.model is resolved first (see below). Optional if every provider
    // declares a model; kept for legacy single-guard apps.
    'user' => [
        'model' => App\Models\User::class,
    ],

    // guard name (Authenticator subclass) that Auth::guard() constructs.
    'driver_classes' => [
        'session'  => SessionGuard::class,
        'token'    => TokenGuard::class,
        'jwt'      => JwtGuard::class,
        'api_key'  => ApiKeyGuard::class,   // <-- your guard, registered here
    ],

    // provider driver name (used by validateCredentials()/getUserById()/
    // getUserByColumn() helpers) -> DriverInterface handler class.
    'auth_drivers' => [
        'eloquent' => EloquentDriver::class,
        'database' => DatabaseDriver::class,
    ],

    'token_name' => env('TOKEN_NAME', 'auth_token'),
    'jwt_timeout' => 10800,
];
```

### `guards`

Each entry is `driver` (a key into `driver_classes`) plus `provider` (a key into `providers`, used by the base class's credential-lookup helpers). Guard-specific options live alongside those two — `TokenGuard` reads `config('auth.token_name')`, for example; your own guard can read whatever extra keys it needs off the same array, either from the guard's own entry or from a top-level `auth.*` key.

### `driver_classes`

The map `Auth::guard()` actually resolves against. This is the one key you **must** add an entry to for a new guard to be reachable at all. Missing it throws `Driver [<name>] is not supported.` from `Auth::resolveDriverClass()`.

### `providers` and `auth_drivers`

These back the three `protected` helper methods on `Authenticator` (`validateCredentials()`, `getUserById()`, `getUserByColumn()`) — see [Helpers Inherited From `Authenticator`](#helpers-inherited-from-authenticator) below. They're independent of `driver_classes`: `providers` names a user source and which *provider* driver it uses (`eloquent` or `database`), and `auth_drivers` maps that provider driver name to a `DriverInterface` handler class, resolved by `DriverFactory`.

> **The shipped `config/auth.php` stub does not define `auth_drivers`.** `DriverFactory::registerHandlers()` reads `config('auth.auth_drivers', [])`, defaulting to an empty array — so if you never add this key, every call to `validateCredentials()`/`getUserById()`/`getUserByColumn()` throws `Driver [eloquent] is not supported.` even though `providers.users.driver` says `eloquent`. Add the `auth_drivers` map yourself (as shown above) the moment your guard needs those helpers.

> The shipped `DriverInterface` handlers (`EloquentDriver`, `DatabaseDriver`) resolve the **provider's own model** — `config("auth.providers.<provider>.model")` — falling back to the global `config('auth.user.model')` when a provider omits it. So multiple named providers with different user classes (e.g. a staff `User` guard alongside a storefront `Customer` guard) work out of the box; you don't need a custom handler just for that.
>
> **But credential lookup is global within the provider's table, not tenant-scoped.** `attempt()` / `validateCredentials()` match the identifier (e.g. `email`) across the *entire* model/table. If your login identifier is only unique **per tenant** — a multi-tenant app where the same email can belong to two different stores — do **not** rely on `attempt()`: resolve the user scoped to the tenant first (e.g. `getUserByColumn()` on a tenant-scoped query, or look the row up by `tenant_id` + email) and then verify the password. This scoping is an application concern; the framework's credential helpers are deliberately tenant-agnostic.

---

## Writing a Guard

### The `Authenticator` Contract

`Eyika\Atom\Framework\Support\Auth\Guards\Authenticator` is an abstract class. Extend it and implement five abstract methods:

| Method | Purpose |
|---|---|
| `isValid(?string $token): bool` | Validate a token or session without necessarily setting the current user — used by `Auth::isValid()`. |
| `attempt(array $credentials): ?AuthenticatableInterface` | Authenticate given credentials (e.g. `email`/`password`, or `token`), returning the user on success or `null` on failure. Called by `Auth::attempt()`. |
| `refreshJwt(): ?string` | Issue a fresh token for the current session. Guards that don't do token refresh should `throw new NotImplementedException(...)` (that's what `SessionGuard`/`TokenGuard` do). |
| `generateJwt(User $user, ?string $sid = null, bool $is_impersonating = false, ?int $impersonator_id = null, ?int $ttl = null): object` | Mint a token for a user. Non-token guards also throw `NotImplementedException` here. |
| `remember(AuthenticatableInterface $user): void` | Set a long-lived "remember me" cookie. `SessionGuard` implements this with an encrypted cookie; `TokenGuard`/`JwtGuard` throw `NotImplementedException` since the concept doesn't apply to them. |

The constructor signature is fixed by the base class — `__construct(array $config, string $guard)` — because that's exactly what `Auth::guard()` calls with. If you override the constructor (as the shipped `JwtGuard` does, to build a signing-key-aware encoder), keep the same two parameters and still populate `$this->config`/`$this->guard`.

Three **concrete** methods come from the base class and can be left alone or overridden:

```php
public function user(): ?AuthenticatableInterface
{
    return $this->user;   // protected property, defaults to null
}

public function check(): bool
{
    return $this->user() !== null;
}

public function logout(): void
{
    $this->user = null;
}
```

> `user()`'s default implementation just returns the `$user` property — it does **not** look anything up. If your guard doesn't override `user()`, something else in your class must assign `$this->user` (typically inside `attempt()` or a per-request lookup). Every shipped guard (`SessionGuard`, `TokenGuard`, `JwtGuard`) overrides `user()` to resolve the user fresh from the session/header/token on every call rather than relying on the property.

### Helpers Inherited From `Authenticator`

Three `protected` methods do the credential/provider plumbing so your guard doesn't have to know about `providers`/`auth_drivers` directly:

```php
protected function validateCredentials(array $credentials): ?AuthenticatableInterface;
protected function getUserById($id): ?AuthenticatableInterface;
protected function getUserByColumn(string $columnName, $value): ?AuthenticatableInterface;
```

Each resolves `$this->config['guards'][$this->guard]['provider']`, then that provider's `driver`, then asks `DriverFactory::getHandler($driver, $provider)` for a `DriverInterface` instance and delegates to it. Use these instead of querying models directly — they keep your guard provider-agnostic (works against `eloquent` or `database` provider drivers, or a custom one you register).

---

## Worked Example: An API-Key Guard

A guard that authenticates a request by a per-user API key sent as an `X-Api-Key` header, looked up against a DB column via the standard provider plumbing:

```php
<?php

namespace App\Http\Guards;

use Eyika\Atom\Framework\Exceptions\NotImplementedException;
use Eyika\Atom\Framework\Support\Auth\Contracts\AuthenticatableInterface;
use Eyika\Atom\Framework\Support\Auth\Guards\Authenticator;
use Eyika\Atom\Framework\Support\Auth\User;
use Eyika\Atom\Framework\Support\Facade\Request;

class ApiKeyGuard extends Authenticator
{
    /** Column on the provider's user table/model that stores the key. */
    protected string $keyColumn = 'api_key';

    public function attempt(array $credentials): ?AuthenticatableInterface
    {
        if (empty($credentials['api_key'])) {
            return null;
        }

        if (!$user = $this->getUserByColumn($this->keyColumn, $credentials['api_key'])) {
            return null;
        }

        $this->user = $user;
        return $user;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?AuthenticatableInterface
    {
        if ($this->user !== null) {
            return $this->user;
        }

        if (!$key = Request::header('X-Api-Key')) {
            return null;
        }

        return $this->user = $this->getUserByColumn($this->keyColumn, $key);
    }

    public function isValid(?string $token): bool
    {
        if (!$token) {
            return false;
        }
        return $this->getUserByColumn($this->keyColumn, $token) !== null;
    }

    public function logout(): void
    {
        $this->user = null;
    }

    public function refreshJwt(): ?string
    {
        throw new NotImplementedException('Api-key guard does not implement the refresh token method');
    }

    public function generateJwt(User $user, ?string $sid = null, bool $is_impersonating = false, ?int $impersonator_id = null, ?int $ttl = null): object
    {
        throw new NotImplementedException('Api-key guard does not generate jwt tokens');
    }

    public function remember(AuthenticatableInterface $user): void
    {
        throw new NotImplementedException('Remember functionality not applicable to api-key guards');
    }
}
```

Notes on this example:

- `attempt()` and `user()`/`isValid()` both go through `getUserByColumn()` rather than querying `App\Models\User` directly, so the guard works against whatever `providers.users.driver` is configured (`eloquent` today, `database` tomorrow, without touching this class).
- Anything the API-key mechanism has no analogue for (`refreshJwt()`, `generateJwt()`, `remember()`) throws `NotImplementedException` — the same pattern `SessionGuard`/`TokenGuard` use for methods that don't apply to them. Don't return `null`/no-op silently for these; a caller that expects a real token back should get a loud failure, not a guard that quietly does nothing.
- `Auth::attempt($credentials, $remember)` calls `$guard->remember($user)` only `if ($remember && method_exists($guard, 'remember'))` — `method_exists()` is true here (the method exists, it just throws), so passing `remember: true` against this guard will raise the `NotImplementedException`. Don't pass `remember: true` for guards that don't support it.

---

## Registering the Driver Class

Add the class to `config('auth.driver_classes')`, keyed by whatever driver name you'll reference from a guard:

```php
// config/auth.php
use App\Http\Guards\ApiKeyGuard;

'driver_classes' => [
    'session' => \Eyika\Atom\Framework\Support\Auth\Guards\SessionGuard::class,
    'token'   => \Eyika\Atom\Framework\Support\Auth\Guards\TokenGuard::class,
    'jwt'     => \Eyika\Atom\Framework\Support\Auth\Guards\JwtGuards\JwtGuard::class,
    'api_key' => ApiKeyGuard::class,
],
```

There's no `extend()`-style programmatic registration API for guards (unlike [`GrammarFactory::extend()`](database-grammars#programmatic--grammarfactoryextend) for database grammars) — `driver_classes` in config is the only registration point `Auth::resolveDriverClass()` reads.

If you need the provider-lookup helpers (`validateCredentials()`/`getUserById()`/`getUserByColumn()`) to work, also make sure `config('auth.auth_drivers')` has an entry for whatever driver your `providers.*.driver` uses (see the [`auth_drivers`](#providers-and-auth_drivers) callout above) — this is easy to miss because it's a separate map from `driver_classes` and the shipped config stub doesn't include it by default.

---

## Selecting the Guard

Point a named guard at the driver, and give it a `provider`:

```php
// config/auth.php
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],
    'devices' => [
        'driver' => 'api_key',
        'provider' => 'users',
    ],
],
```

Use it explicitly:

```php
use Eyika\Atom\Framework\Support\Auth\Auth;

if (Auth::guard('devices')->check()) {
    $user = Auth::guard('devices')->user();
}
```

Or make it the default for all unqualified `Auth::` calls (`Auth::user()`, `Auth::check()`, `Auth::attempt()`, …) by pointing `defaults.guard` at it:

```php
'defaults' => [
    'guard' => 'devices',
],
```

> Remember `Auth::getDefaultGuard()`'s actual rule: it's `devices` only for non-JSON requests. A request where `Request::wantsJson()` is true always resolves the `api` guard name, regardless of `defaults.guard` — so an `api`-keyed entry must exist in `guards` for any app serving JSON.

---

## Resolving Guards at Runtime

```php
Auth::guard();            // resolves auth.defaults.guard (or 'api' for JSON requests)
Auth::guard('devices');   // resolves the named guard explicitly
Auth::guard('missing');   // throws InvalidArgumentException: Guard [missing] is not defined.
```

`Auth::user()`, `Auth::check()`, `Auth::attempt()`, `Auth::logout()`, `Auth::isValid()`, and `Auth::refreshJwt()` all internally call `Auth::guard()` with no name, so they act on the default guard. To act on a specific guard, call the guard's methods directly (`Auth::guard('devices')->attempt($credentials)`), not through the `Auth` facade methods.

---

## Gotchas

- **`driver_classes` is the required key.** Forgetting to add your guard class there is the most common cause of `Driver [<name>] is not supported.` — `guards.*.driver` alone isn't enough.
- **`auth_drivers` is a separate map from `driver_classes` and the shipped config stub omits it.** If your guard calls the inherited `validateCredentials()`/`getUserById()`/`getUserByColumn()` helpers, add `auth_drivers` yourself or every one of those calls throws.
- **Guard instances are not cached/singletons.** `Auth::guard($name)` checks an internal `static::$guards[$name]` array first, but nothing in the framework ever writes to it during normal resolution — every `Auth::guard($name)` call constructs a **new** guard instance via `new $driverClass(...)`. Don't rely on mutating state you set on a previously-fetched guard instance persisting into a later `Auth::guard()` call; persist it through `Auth::setJwt()`/`setSid()`/`setUser()`/`setImpersonation()` (static on `Auth`) instead, the way `JwtGuard` does.
- **The constructor signature is load-bearing.** `Auth::guard()` always instantiates as `new $driverClass(static::$config, $name)`. A custom constructor with a different signature (extra required params, different order) breaks resolution.
- **Methods you don't support should throw, not silently no-op.** Follow the shipped guards' convention: `throw new NotImplementedException('...')` for `refreshJwt()`/`generateJwt()`/`remember()` when the mechanism doesn't have an analogue, rather than returning `null` or doing nothing.
- **Provider drivers use the provider's model, but credential lookup is not tenant-scoped.** `EloquentDriver`/`DatabaseDriver` build the returned user from `config("auth.providers.<provider>.model")` (falling back to the global `auth.user.model`), so multiple providers with different user classes work. However `attempt()`/`validateCredentials()` match the identifier across the provider's whole table — for per-tenant-unique identifiers, scope the lookup to the tenant yourself before verifying (see the callout under [`providers` and `auth_drivers`](#providers-and-auth_drivers)).
- **Under a persistent worker, call `Auth::flush()` between requests.** It resets the resolved user, jwt, sid, impersonation flags, and the (unused-but-declared) guard cache — without it, static state from one request's guard resolution can leak into the next.

---

## Related

- [Authorization](../authorization) — role/permission checks and middleware built on top of the authenticated user.
- [Security](../advanced/security) — the shipped `session`/`token`/`jwt` guards and general security posture.
- [Middleware](../middleware) — where guard checks (`Auth::check()`, attaching `$request->auth_user`) typically run.
- [Custom Database Grammars](database-grammars) — the analogous `extend()`-plus-config registration pattern for a different subsystem.