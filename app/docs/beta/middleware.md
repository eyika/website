# Middlewares In Atom

## Introduction

Middleware in the Atom framework acts as a bridge between an HTTP request and a response. It provides a mechanism to inspect, modify, or reject incoming requests before they reach your application's core logic — and to inspect or modify outgoing responses before they are sent to the client.

Atom middleware operates using a `handle(Request $request, Closure $next)` method. Each middleware either **passes the request along** by calling `$next($request)`, or **short-circuits** the pipeline by returning a response of its own. Middleware is essential for common tasks such as authentication, authorization, logging, CORS, and data transformation.

---

## Middleware Structure

Middleware classes implement the `MiddlewareInterface` from the Atom framework. The interface defines a single `handle` method that receives the `Request` and a `Closure $next`, and returns a `BaseResponse`.

### Sample Middleware Structure
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
        // ... inspect or mutate the request here ...

        $response = $next($request); // hand off to the next middleware / controller

        // ... inspect or mutate the response here ...

        return $response;
    }
}
```

> To **stop** the request, return a response instead of calling `$next($request)`. To let it **proceed**, return `$next($request)`.

---

## Creating Middleware

Scaffold a middleware with the console:
```bash
php artisan make:middleware EnsureUserIsAuthenticated
```

Or create it manually as a class implementing `MiddlewareInterface` in the `App\Http\Middlewares` namespace.

### Example: `EnsureUserIsAuthenticated`
Below is an example middleware that authenticates the user and attaches them to the request.

```php
<?php

namespace App\Http\Middlewares;

use Closure;
use Exception;
use PDOException;
use Eyika\Atom\Framework\Http\BaseResponse;
use Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Auth\Guard;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

class EnsureUserIsAuthenticated implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): BaseResponse
    {
        try {
            // Attempt to authenticate the user.
            if (!$user = Guard::tryToAuthenticate()) {
                return JsonResponse::unauthorized(); // short-circuit: stop here
            }

            $user = $user->find(is_protected: false);
            $request->auth_user = $user; // attach the authenticated user to the request
        } catch (PDOException | Exception $e) {
            consoleLog(0, $e->getMessage() . ' ' . $e->getTraceAsString());
            return JsonResponse::unauthorized();
        }

        return $next($request); // allow the request to proceed
    }
}
```

> Note that response classes (`JsonResponse`, `Response`) are called via their **facades** in `Eyika\Atom\Framework\Support\Facade`, which is what lets you call them statically.

---

## The HTTP Kernel

Middleware is registered and organized in your application's HTTP Kernel at `app/Http/Kernel.php`, which extends the framework's `Foundation\Kernel`. The Kernel exposes four collections:

```php
<?php

namespace App\Http;

use Eyika\Atom\Framework\Foundation\Kernel as FoundationKernel;
use App\Http\Middlewares\TrimStrings;
use App\Http\Middlewares\TrustProxies;
use App\Http\Middlewares\HandleCors;
use Eyika\Atom\Framework\Http\Middlewares\StartSession;
use Eyika\Atom\Framework\Http\Middlewares\SubstituteBindings;
// ...

class Kernel extends FoundationKernel
{
    /** Global middleware — runs on every request. */
    protected $middleware = [
        TrustProxies::class,
        // PreventRequestsDuringMaintenance::class,
        // ValidatePostSize::class,
        TrimStrings::class,
        // ConvertEmptyStringsToNull::class,
    ];

    /** Middleware groups — referenced by name from a route map's ->middleware(...). */
    protected $middlewareGroups = [
        'web' => [
            StartSession::class,
            SubstituteBindings::class,
            // ...
        ],
        'api' => [
            HandleCors::class,
            SubstituteBindings::class,
            // ...
        ],
    ];

    /** Short aliases you can use when assigning middleware to routes. */
    protected $middlewareAliases = [
        // 'auth' => EnsureUserIsAuthenticated::class,
    ];

    /** Forces non-global middleware into a fixed relative order. */
    protected $middlewarePriority = [
        // ...
    ];
}
```

- **`$middleware`** — global middleware, run on every request.
- **`$middlewareGroups`** — named stacks (`web`, `api`, or your own). A route map applies a group via `Route::map('web')->middleware('web')` (see the [Routing](routing) docs).
- **`$middlewareAliases`** — short names (e.g. `'auth'`) you can reference when attaching middleware to a route.
- **`$middlewarePriority`** — forces a fixed relative order for non-global middleware.

---

## App-level vs Framework-shipped Middleware

The framework **ships** a set of ready-to-use middleware under `Eyika\Atom\Framework\Http\Middlewares\`:

- `ConvertEmptyStringsToNull`
- `ServePublicAssets`
- `ShareErrorsFromSession`
- `StartSession`
- `SubstituteBindings`
- `ValidatePostSize`
- `ValidateSignature`
- `VerifyCsrfToken`
- `EnsureEmailIsVerified`

Your application also ships **app-level** middleware in `app/Http/Middlewares/` that you own and can edit freely:

- `TrustProxies`
- `HandleCors`
- `EncryptCookies`
- `PreventRequestsDuringMaintenance`
- `TrimStrings`

Add your own middleware alongside these in `app/Http/Middlewares/`.

---

## Using Middleware

### Applying Middleware to Routes
Assign middleware to a specific route by chaining `middleware()`:

```php
use Eyika\Atom\Framework\Http\Route;

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');
```

### Middleware in Route Groups
Apply middleware to a group of routes by staging it with `Route::middleware(..., false)` before the group, or by passing a closure:
```php
Route::middleware('auth', function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/settings', [SettingsController::class, 'update']);
});
```

### Middleware Parameters
Middleware may take runtime parameters using a `:` suffix (comma-separated). They arrive as extra arguments to `handle()`:
```php
Route::get('/reports', [ReportController::class, 'index'])->middleware('throttle:60,1');
```
```php
public function handle(Request $request, Closure $next, string $max, string $perMinutes): BaseResponse
{
    // ...
    return $next($request);
}
```

### Global Middleware
Global middleware runs on every request. Register it in the Kernel's `$middleware` array (shown above), not through an ad-hoc router call.

---

## Middleware Return Values

Every middleware must ultimately return a `BaseResponse`:
- **Return `$next($request)`** to let the request continue through the pipeline.
- **Return a response object** (e.g. `JsonResponse::unauthorized()`) to stop the request and send that response back to the client.

### Example: Blocking Unauthorized Users
```php
public function handle(Request $request, Closure $next): BaseResponse
{
    if (!$request->auth_user) {
        return JsonResponse::unauthorized();
    }
    return $next($request);
}
```

---

## Registering Middleware

Middleware is registered in the HTTP Kernel (`app/Http/Kernel.php`):
- add it to `$middleware` to run globally,
- add it to a `$middlewareGroups` stack (`web` / `api` / a custom group), or
- give it an alias in `$middlewareAliases` so routes can reference it by a short name.

```php
protected $middlewareAliases = [
    'auth'  => \App\Http\Middlewares\EnsureUserIsAuthenticated::class,
    'admin' => \App\Http\Middlewares\EnsureUserIsAdmin::class,
];
```

---

## Error Handling in Middleware

Middleware should handle exceptions gracefully to avoid application crashes. Use try-catch blocks and return an appropriate response.

### Example
```php
public function handle(Request $request, Closure $next): BaseResponse
{
    try {
        // ... logic ...
        return $next($request);
    } catch (PDOException $e) {
        consoleLog(0, $e->getMessage());
        return JsonResponse::serverError();
    } catch (Exception $e) {
        consoleLog(0, $e->getMessage());
        return JsonResponse::serverError();
    }
}
```

---

## Testing Middleware

Middleware can be tested independently by creating a request and a simple `$next` closure, then asserting on the returned response.

### Example Test
```php
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\Response;

$middleware = new EnsureUserIsAuthenticated();
$request = new Request();

// $next just echoes a success response when the request is allowed through.
$next = fn (Request $req) => Response::plain('ok');

$response = $middleware->handle($request, $next);
assert($response->status === 401); // unauthenticated request was blocked
```

For a full end-to-end check, exercise the middleware through the framework's integration test case, which runs the entire route + middleware pipeline.

---

## Conclusion

Middleware in Atom is a powerful feature that provides fine-grained control over your application's request and response cycle. By implementing the `MiddlewareInterface` — calling `$next($request)` to continue or returning a response to stop — and registering middleware through the HTTP Kernel, you can create reusable, testable components that enhance your application's functionality and security.

For additional features and advanced middleware capabilities, consult the framework's extended documentation or source code.
