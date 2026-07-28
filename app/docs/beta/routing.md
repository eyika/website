# Routing in Atom

## Introduction
The routing system in Atom is designed to be robust and flexible, allowing developers to define routes that map HTTP requests to specific actions within their application. The router supports dynamic route parameters, named routes, route groups, middleware, and custom HTTP verbs, making it easy to build modern, scalable web applications.

Unlike earlier versions, the framework no longer hardcodes a web-vs-api heuristic. Instead, **your application owns how requests are wired to route files** through the `RouteServiceProvider`. This gives you full control over which route file handles a request, which middleware group it runs through, and whether it behaves statefully (web) or statelessly (api).

---

## Route Service Provider & Maps

Route files are wired to requests in `app/Providers/RouteServiceProvider::boot()` using **route maps**. Each `Route::map()` declares a matcher (`when()`), a middleware group, an optional `stateless()` flag, and the route file it loads.

```php
<?php

namespace App\Providers;

use Eyika\Atom\Framework\Foundation\ServiceProvider;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Http\Route;

class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // API: JSON/AJAX/OPTIONS requests, or anything under /api. Stateless
        // (no "previous URL" session write).
        Route::map('api')
            ->middleware('api')
            ->stateless()
            ->when(fn (Request $request) =>
                $request->wantsJson()
                || $request->isXmlHttpRequest()
                || $request->isOptions()
                || str_starts_with('/' . ltrim(strtok($request->pathInfo(), '?'), '/'), '/api'))
            ->load(base_path('routes/api.php'));

        // Web: the fallback for everything else (no matcher).
        Route::map('web')
            ->middleware('web')
            ->load(base_path('routes/web.php'));
    }
}
```

**How maps are resolved:** the router consults maps in registration order. The **first** map whose `when()` matcher accepts the request handles it. A map with **no** `when()` matcher is the fallback (it matches any request) — always list it last. You are free to add your own map types (`admin`, `webhook`, `docs`, …).

### Map builder methods
- **`middleware(string $group)`**: the Kernel middleware group (e.g. `'web'`, `'api'`) applied to every route in the map's file.
- **`when(callable $matcher)`**: a predicate `fn(Request $request): bool` deciding whether this map handles the request. Omit it to make the map a fallback.
- **`stateless(bool $stateless = true)`**: mark the map as API-like — no "previous URL" session write on GET. Stateful maps (the default) behave like web routes.
- **`load(string $file)`**: the route file this map loads. This is the terminal builder call.

---

## Defining Routes

Routes in Atom are defined in the `routes/web.php` or `routes/api.php` files (or any custom route file loaded by a map). Each route is associated with an HTTP method and a callback or controller action.

### Example
```php
use Eyika\Atom\Framework\Http\Route;
use Eyika\Atom\Framework\Support\Facade\Response;

Route::get('/', function () {
    return Response::view('index');
});

Route::post('/submit', [FormController::class, 'submit']);
```

> Use `'/'` for the root route.

### Supported HTTP Methods
The routing system supports the following HTTP methods:
- `GET`
- `POST`
- `PUT`
- `PATCH`
- `DELETE`

For all methods, use `any()` (registered under an `ANY` bucket that dispatches when no method-specific route matches):
```php
Route::any('/endpoint', [SomeController::class, 'anyMethod']);
```

For an arbitrary/custom HTTP verb, use `custom()`:
```php
Route::custom('PURGE', '/cache', [CacheController::class, 'purge']);
```

For multiple specific methods on the same URI, use:
```php
Route::match(['get', 'post'], '/form', [FormController::class, 'handle']);
```

> Note: `Route::match` is not yet implemented (We'll Be Glad To Get A PR From You). For now, declare the route once per method, or use `Route::any`.

---

## Dynamic Route Parameters

You can define dynamic segments in your routes using curly braces `{}`. Route parameters are passed to your handler as positional arguments **after** the `Request`.

### Example
```php
Route::get('/user/{id}', function (Request $request, $id) {
    return "User ID: $id";
});
```

### Optional Parameters
Optional parameters are specified with a `?`:
```php
Route::get('/post/{id?}', function (Request $request, $id = null) {
    return $id ? "Post ID: $id" : "No Post ID provided";
});
```

> Parameter values are URL-decoded automatically (e.g. `Simple%20RSI` becomes `Simple RSI`) before they reach your handler.

---

## Named Routes

Assign names to routes for easier referencing:
```php
Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
```

Retrieve the route URL using the `route()` helper:
```php
$url = route('dashboard');
```

Pass parameters to named routes:
```php
Route::get('/profile/{id}', [UserController::class, 'profile'])->name('profile');
$url = route('profile', ['id' => 42]);
```

---

## Route Groups

Route groups let you apply a common URI prefix (and, combined with `middleware()`, a shared middleware stack) to multiple routes. `Route::group()` takes a **string prefix** and a callback.

### Example
```php
Route::group('admin', function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/settings', [AdminController::class, 'settings']);
});
```

The routes above resolve to `/admin/dashboard` and `/admin/settings`.

### Group Middleware
Chain `Route::middleware(..., false)` before `group()` to apply middleware to every route in the group. Passing `false` as the second argument stages the middleware for the next group:
```php
Route::middleware(SomeMiddleware::class, false)->group('admin', function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/settings', [AdminController::class, 'settings']);
});
```

Alternatively, `Route::middleware($middleware, $callback)` applies middleware to every route declared inside the closure:
```php
Route::middleware('auth', function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/settings', [SettingsController::class, 'update']);
});
```

### Domain Groups
Restrict a group to one or more host names:
```php
Route::domain('admin.example.com', function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
});
```

---

## Middleware

Middleware can be applied to an individual route by chaining `middleware()` after the route definition:
```php
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');
```

You can pass a single middleware or an array, and middleware may carry `:param` arguments (e.g. `'throttle:60,1'`). See the [Middleware](middleware) docs for how groups and the Kernel resolve middleware.

---

## Route Constraints

Constraining route parameters to a regex pattern (`->where(...)`) and global patterns (`Route::pattern(...)`) are **not yet implemented** (We'll Be Glad To Get A PR From You). For now, validate parameters inside your controller or a middleware.

---

## Fallback / Not-Found Routes

To handle unmatched requests, register an `ANY` route at `/404`. The dispatcher invokes it when no other route matches:
```php
Route::any('/404', function () {
    return Response::html('Page Not Found', 404);
});
```

If no `/404` route is defined, the framework throws a `NotFoundHttpException`, which the exception handler renders.

---

## Advanced Usage

### Route Prefixing
Prefix a set of routes under a common URI segment using a route group:
```php
Route::group('api', function () {
    Route::get('/users', [ApiController::class, 'users']); // → /api/users
});
```

> A dedicated `Route::prefix()->group()` chain is not implemented — use `Route::group('api', ...)` as shown above.

### Custom HTTP Verbs
Define a custom HTTP verb for a route with `custom()`:
```php
Route::custom('CUSTOM', '/custom-endpoint', [CustomController::class, 'handle']);
```

### Route Macros
Adding custom router methods via `Route::macro(...)` is not yet implemented (We'll Be Glad To Get A PR From You).

---

## Generating URLs

### Using the `route()` Helper
Generate URLs for named routes:
```php
$url = route('profile', ['id' => 42]);
```

### Using the `url()` Helper
Create URLs for arbitrary paths:
```php
$url = url('/contact');
```

---

## Route Caching

For production, compile the registered routes into a cache artifact:
```bash
php artisan route:cache
php artisan route:clear
```

Route files that contain closure callbacks are **not** cached (closures can't be serialized) — they are always required and stay dynamically registered. Prefer controller `[Controller::class, 'method']` callbacks in files you intend to cache.

---

## Conclusion

The routing system in Atom is designed to be intuitive and powerful. With app-owned route maps, dynamic parameters, named routes, middleware, and route groups, you can build scalable and maintainable web applications effortlessly.

For more advanced features and customization, refer to the advanced topics section or explore the framework's source code.
