# Custom Middleware

This page goes deeper on **authoring** middleware than the [Middleware](../middleware) usage guide — the mechanics of `MiddlewareInterface`, the before/after pattern, when to short-circuit vs call `$next`, how parameters are parsed off the pipe string, and every place the HTTP Kernel lets you register a middleware. Read the usage guide first if you haven't already; this page assumes you know what middleware is for and focuses on how to build one correctly.

## Table of Contents

- [The Contract](#the-contract)
- [The Before/After Pattern](#the-beforeafter-pattern)
- [Returning Early vs Calling $next](#returning-early-vs-calling-next)
- [What Counts as a Valid Return](#what-counts-as-a-valid-return)
- [Passing Parameters to Middleware](#passing-parameters-to-middleware)
- [How Middleware Is Instantiated](#how-middleware-is-instantiated)
- [Registering Middleware](#registering-middleware)
  - [Global Middleware](#global-middleware)
  - [Route Middleware](#route-middleware)
  - [Middleware Groups](#middleware-groups)
  - [Middleware Aliases](#middleware-aliases)
  - [Middleware Priority](#middleware-priority)
- [Where Middleware Runs in the Request Lifecycle](#where-middleware-runs-in-the-request-lifecycle)
- [Gotchas](#gotchas)
- [Conclusion](#conclusion)

---

## The Contract

Every middleware implements `Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface`:

```php
namespace Eyika\Atom\Framework\Http\Contracts;

use Closure;
use Eyika\Atom\Framework\Http\BaseResponse;
use Eyika\Atom\Framework\Http\Request;

interface MiddlewareInterface
{
    public function handle(Request $request, Closure $next): BaseResponse|string;
}
```

There is exactly one method to implement: `handle()`. It receives the current `Request` and a `Closure $next` that represents "the rest of the pipeline" — every middleware after this one, followed by the matched route's controller/callback. Note the return type is `BaseResponse|string`; a middleware is allowed to return a plain string as well as a `BaseResponse` (more on this below).

```php
<?php

namespace App\Http\Middlewares;

use Closure;
use Eyika\Atom\Framework\Http\BaseResponse;
use Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface;
use Eyika\Atom\Framework\Http\Request;

class SampleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): BaseResponse
    {
        return $next($request);
    }
}
```

Scaffold this skeleton with the console:

```bash
php artisan make:middleware SampleMiddleware
```

This creates `app/Http/Middlewares/SampleMiddleware.php` under the `App\Http\Middlewares` namespace with a `handle()` that just calls `$next($request)` — a pass-through you then fill in.

---

## The Before/After Pattern

`$next` is just a closure — calling it doesn't return until the *entire rest of the pipeline* has run and produced a response. That gives every middleware two natural places to act:

- **Before** — any code you put **above** `$next($request)` runs on the way *in*, before the route handler executes. Use this to inspect/reject the request, attach data to it (`$request->auth_user = $user`), or short-circuit entirely.
- **After** — any code you put **below** the `$next($request)` call runs on the way *out*, once the route handler (and everything deeper in the pipeline) has already produced a response. Use this to inspect or mutate that response — add headers, log the outcome, etc.

A middleware doesn't have to do both. Most middleware are purely "before" (auth checks, input normalization) or purely "after" (adding a header), but you can combine them — for example, to time a request:

```php
<?php

namespace App\Http\Middlewares;

use Closure;
use Eyika\Atom\Framework\Http\BaseResponse;
use Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface;
use Eyika\Atom\Framework\Http\Request;

class LogRequestDuration implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): BaseResponse
    {
        $start = microtime(true); // --- before ---

        $response = $next($request); // hand off to the rest of the pipeline

        $elapsedMs = (microtime(true) - $start) * 1000; // --- after ---
        logger()->info(sprintf(
            '%s %s handled in %.2fms',
            $request->method(),
            $request->requestUri(),
            $elapsedMs
        ));

        return $response;
    }
}
```

Or a pure "after" middleware that stamps a header on every outgoing response:

```php
public function handle(Request $request, Closure $next): BaseResponse
{
    $response = $next($request);

    return $response->setHeader('X-App-Version', config('app.version', 'dev'));
}
```

> `BaseResponse::setHeader()` returns `$this`, so you can chain straight off the value `$next()` gave you and return the result in one line.

---

## Returning Early vs Calling $next

`$next($request)` is what **continues** the pipeline. If your middleware never calls it, nothing after it — later middleware, the matched controller, everything — ever runs. That's the entire short-circuit mechanism: **return a response instead of calling `$next`** to stop the request in its tracks.

```php
public function handle(Request $request, Closure $next): BaseResponse
{
    if (!$user = Guard::tryToAuthenticate()) {
        return JsonResponse::unauthorized(); // stop here — $next is never called
    }

    $user = $user->find(is_protected: false);
    $request->auth_user = $user;

    return $next($request); // proceed
}
```

You are not limited to returning a response to stop the request — throwing an exception works too, and is often cleaner for "hard" failures. Two of the framework's shipped middleware do exactly this: `EnsureEmailIsVerified` and [`ValidateSignature`](../advanced/security), the latter backing signed URLs:

```php
public function handle(Request $request, Closure $next): BaseResponse
{
    if (!$this->isEmailVerified($request)) {
        throw new RequestException(403, 'Your email address is not verified.');
    }

    return $next($request);
}
```

The exception propagates up out of the pipeline and is handled by the application's registered exception handler, the same as any other exception thrown during the request — so it still ends up as a proper HTTP error response, you just don't build that response by hand.

> A middleware can call `$next($request)` more than once, wrap it in a `try`/`catch`, or skip it entirely based on a condition — it's a plain closure. The one rule is: whatever code path you take, you must end by returning a `BaseResponse` (or a string — see below).

---

## What Counts as a Valid Return

The pipeline enforces the return type at runtime, not just via the interface's type hint. After calling a middleware's `handle()`, the framework's `Pipeline` checks the result:

```php
$resp = method_exists($pipe, 'handle')
    ? $pipe->handle($passable, $next, ...$parameters)
    : $pipe($passable, $next);

if (!$resp instanceof BaseResponse && !is_string($resp)) {
    $pipename = getCallableName($pipe);
    throw new BaseException("$pipename must return a BaseResponse object or string");
}
```

So a middleware may return either:
- a `BaseResponse` instance — the normal case (`JsonResponse::unauthorized()`, `Response::view(...)`, or whatever `$next($request)` gave back), or
- a plain `string` — the router wraps a bare string in `Response::plain()` before it's sent, so `return 'ok';` is technically valid from a terminal middleware, though in practice you'll almost always be forwarding a `BaseResponse` from `$next()` or a facade call.

Anything else — `null`, `void`, an array, `true` — throws a `BaseException` and turns into a 500. This is the single most common bug when writing a new middleware: a code path (often an early `if` with no `else`) that falls through without an explicit `return`.

---

## Passing Parameters to Middleware

Middleware can accept extra, per-route arguments by appending them to the pipe string with a `:`, comma-separated:

```php
Route::get('/reports', [ReportController::class, 'index'])->middleware('throttle:60,1');
```

The `Pipeline` splits the string on `:` and the parameters on `,` before instantiating the middleware, then splats them onto `handle()` after `$request` and `$next`:

```php
protected function resolveMiddleware(string|array $pipe)
{
    $parts = is_array($pipe) ? $pipe : explode(':', $pipe, 2);
    $pipeClass = $parts[0];
    $parameters = isset($parts[1]) ? explode(',', $parts[1]) : [];
    return [$pipeClass, $parameters];
}
```

Your `handle()` signature declares the extra parameters as ordinary method arguments, **as strings** (they come straight out of `explode(',', ...)`, so cast/validate as needed):

```php
<?php

namespace App\Http\Middlewares;

use Closure;
use Eyika\Atom\Framework\Http\BaseResponse;
use Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface;
use Eyika\Atom\Framework\Http\Request;

class Throttle implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string $maxAttempts, string $perMinutes): BaseResponse
    {
        $max = (int) $maxAttempts;
        $window = (int) $perMinutes;

        // ... rate-limit logic keyed on $request->ip() using $max / $window ...

        return $next($request);
    }
}
```

`middleware('throttle:60,1')` calls `handle($request, $next, '60', '1')`. If a route omits the parameters entirely (`middleware('throttle')`), `$parameters` is an empty array — so any parameter without a default value would raise a PHP "too few arguments" error. Give parameters a default when a middleware is sometimes used bare:

```php
public function handle(Request $request, Closure $next, string $maxAttempts = '60'): BaseResponse
```

There is also an **array pipe form** that says the same thing as a `Class:params` string without the string parsing: `[Throttle::class, '60,1']` — a two-element array whose first element is the class and second is the comma-separated parameter string. `Pipeline::resolveMiddleware()` accepts either form.

> Passing `->middleware(['auth', 'admin'])` expecting to attach **two** middleware in one call does not do what it looks like it does — `Route::middleware()` treats a multi-string array as the `[Class, params]` pipe form above, so it resolves to a single `auth` middleware invoked with `'admin'` as its first string parameter, and `admin` never runs as its own middleware. To attach more than one middleware, chain separate calls (`->middleware('auth')->middleware('admin')`) or list them together in the Kernel's `$middlewareGroups`/`$middlewareAliases` instead.

---

## How Middleware Is Instantiated

This matters if your middleware needs a dependency. The pipeline resolves a middleware class with a **bare `new`**, not through the service container:

```php
$pipe = is_callable($pipeClass) ? $pipeClass : new $pipeClass;
```

> **There is no constructor dependency injection for middleware.** A middleware class cannot declare required constructor parameters — the framework has no values to pass and `new $pipeClass` will fatal on a missing argument. If your middleware needs a framework service (the container, a config value, a repository), pull it in from **inside** `handle()` instead, via a helper (`app()`, `config()`) or a facade — the same way `EnsureUserIsAuthenticated` reaches for `Guard::tryToAuthenticate()`:

```php
public function handle(Request $request, Closure $next): BaseResponse
{
    $mailer = app()->make(\App\Services\Mailer::class); // resolve inside handle(), not the constructor

    // ...

    return $next($request);
}
```

A constructor with only **optional** parameters (defaults) is fine, since `new $pipeClass` calls it with zero arguments.

---

## Registering Middleware

Middleware only runs if it's wired into the request somewhere. There are four places to do that, and they compose: a single request can pass through global middleware, then a middleware group, then middleware attached to the specific matched route.

All four are declared on your application's HTTP Kernel, `app/Http/Kernel.php`, which extends the framework's `Foundation\Kernel` and implements `Foundation\Contracts\Kernel`:

```php
<?php

namespace App\Http;

use Eyika\Atom\Framework\Foundation\Kernel as FoundationKernel;
use App\Http\Middlewares\TrimStrings;
use App\Http\Middlewares\TrustProxies;
use App\Http\Middlewares\HandleCors;
use App\Http\Middlewares\EnsureUserIsAuthenticated;
use Eyika\Atom\Framework\Http\Middlewares\StartSession;
use Eyika\Atom\Framework\Http\Middlewares\SubstituteBindings;

class Kernel extends FoundationKernel
{
    /** Global middleware — runs on every request. */
    protected $middleware = [
        TrustProxies::class,
        TrimStrings::class,
    ];

    /** Middleware groups — referenced by name from a route map's ->middleware(...). */
    protected $middlewareGroups = [
        'web' => [
            StartSession::class,
            SubstituteBindings::class,
        ],
        'api' => [
            HandleCors::class,
            SubstituteBindings::class,
        ],
    ];

    /** Short aliases you can use when assigning middleware to routes. */
    protected $middlewareAliases = [
        'auth' => EnsureUserIsAuthenticated::class,
    ];

    /** Forces non-global middleware into a fixed relative order. */
    protected $middlewarePriority = [
    ];
}
```

The base `Foundation\Kernel` implements the `Kernel` contract with four getters — `getMiddlewares()`, `getMiddlewareGroups()`, `getMiddlewareAliases()`, `getMiddlewarePriority()` — that simply return these four protected arrays. The framework only ever reads middleware through this contract, so your Kernel is the single source of truth for what's registered.

### Global Middleware

Anything listed in `$middleware` runs on **every** request that reaches the router, regardless of route or middleware group. Use it for concerns that apply application-wide — trusted-proxy resolution, string trimming, request-size validation. There's no per-route opt-out for global middleware; if a route shouldn't go through it, that logic belongs inside the middleware itself (e.g. checking `$request->is('webhooks/*')` and calling `$next($request)` immediately).

### Route Middleware

Attach middleware to one route or a group of routes at the point they're declared, via `Route::middleware()`. See [Middleware](../middleware#applying-middleware-to-routes) and the [Routing](../routing) guide for the full set of call shapes (`->middleware('auth')` on a single route, `Route::middleware('auth', function () { ... })` wrapping several, or `Route::middleware(X::class, false)` staged in front of a `Route::group()`). This is "your own" middleware entry — you write the class, then reference it by class name or alias exactly where it's needed instead of running it globally.

### Middleware Groups

`$middlewareGroups` are named stacks — `web`, `api`, or any name you invent — each holding an ordered list of middleware. They exist so a whole *route file* can be routed through a consistent stack without re-listing every middleware at every route. A `RouteServiceProvider` wires a group to a route file when it registers the map:

```php
Route::map('api')
    ->middleware('api')      // resolves the 'api' key of $middlewareGroups
    ->stateless()
    ->when(fn (Request $r) => $r->wantsJson())
    ->load(base_path('routes/api.php'));
```

Every route declared in that file then runs through the `api` group's middleware in addition to the global `$middleware` stack. See the [Routing](../routing) guide for the full `Route::map()` API. You aren't limited to `web`/`api` — add your own key (e.g. `'admin'`) to `$middlewareGroups` and reference it the same way from a map, or from `Route::middleware('admin', function () { ... })` around a set of routes.

### Middleware Aliases

`$middlewareAliases` gives a middleware class a short name you can use where a full `::class` reference would be verbose — most commonly `'auth'` for whatever your app's authentication middleware is:

```php
protected $middlewareAliases = [
    'auth'  => \App\Http\Middlewares\EnsureUserIsAuthenticated::class,
    'admin' => \App\Http\Middlewares\EnsureUserIsAdmin::class,
];
```

```php
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');
```

Register every custom middleware you want to reference by short name here — there's no separate CLI step, editing the Kernel's `$middlewareAliases` array is the whole registration.

### Middleware Priority

`$middlewarePriority` exists to force a fixed relative order among **non-global** middleware — useful when, say, a session must always start before CSRF verification runs, no matter what order two different route groups happened to list them in. It's empty by default; leave it empty unless you've actually hit an ordering bug between two middleware that both need to run before/after each other in a specific sequence.

---

## Where Middleware Runs in the Request Lifecycle

For a matched route, the framework merges `$middleware` (global) with the matched route's own `middlewares` entry, then hands the combined list to `Pipeline`:

```php
$middlewares = array_merge(
    static::$defaultMiddlewares, // global + the active map's group, loaded from the Kernel
    $matched['middlewares'] ?? []  // whatever ->middleware(...) attached to this specific route
);

$response = (new Pipeline())
    ->through($middlewares)
    ->then($coreHandler) // the matched controller/callback, or a 404 handler if nothing matched
    ->run($request);
```

`Pipeline::run()` folds the list into nested closures with `array_reduce`, innermost-first, so middleware execute **in the order they appear in the merged list**, each one's `$next` being the next middleware's `handle()` (or the final controller once the list is exhausted). That's why the before/after split matters: the *before* halves run top-to-bottom in list order, and the *after* halves unwind bottom-to-top as each `$next()` call returns.

---

## Gotchas

- **No `return` on every path throws a 500.** Every branch of `handle()` must end in `return $next($request)` or `return <a BaseResponse or string>` — falling off the end of the method (or an `if` with no matching `else`) trips the `must return a BaseResponse object or string` exception.
- **No constructor DI.** Middleware is instantiated with a bare `new $pipeClass`; resolve services inside `handle()`, not the constructor (see [How Middleware Is Instantiated](#how-middleware-is-instantiated)).
- **Route-level `->middleware()` only attaches to the *last* route registered.** Chaining it after a `Route::get(...)` call works because it looks up "the last inserted route" — don't insert another route in between and then chain `->middleware()`, expecting it to reach back.
- **Middleware parameters are always strings.** `middleware('throttle:60,1')` gives `handle()` the literal strings `'60'` and `'1'`, not integers — cast them yourself.
- **Global middleware has no per-route escape hatch.** If something needs to bypass a global middleware, branch inside that middleware (e.g. on `$request->is(...)`) rather than trying to opt a route out from the outside.
- **A middleware that never calls `$next` silently stops the request.** That's the intended short-circuit mechanism, but it's an easy accidental bug if you forget the call on a success path, not just a failure path.

---

## Conclusion

Authoring middleware in Atom comes down to one interface (`MiddlewareInterface::handle(Request $request, Closure $next): BaseResponse|string`), one decision per middleware (call `$next` to continue, or return/throw to stop), and one place to wire it up (the HTTP Kernel's `$middleware`, `$middlewareGroups`, `$middlewareAliases`, and `$middlewarePriority`). For the everyday usage patterns — applying middleware to a route, a group, or globally — see the [Middleware](../middleware) guide; for how route maps and groups fit into the router as a whole, see [Routing](../routing).