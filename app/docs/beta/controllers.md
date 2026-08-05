# Controllers In Atom Framework

## Introduction

Controllers in Atom framework serve as the entry point for handling HTTP requests. They contain the logic to process incoming requests, interact with models, and return appropriate responses to the client. Controllers ensure that your application follows the **MVC (Model-View-Controller)** pattern, separating concerns and improving code maintainability.

---

## Table of Contents

1. [Controller Structure](#controller-structure)
2. [Creating Controllers](#creating-controllers)
3. [Action Signatures](#action-signatures)
4. [Using Controllers in Routes](#using-controllers-in-routes)
5. [Invokable Controllers](#invokable-controllers)
6. [Resource Controllers](#resource-controllers)
7. [Route Model Binding](#route-model-binding)
8. [Constructors and Dependency Injection](#constructors-and-dependency-injection)
9. [Handling Request Data](#handling-request-data)
10. [Returning Responses](#returning-responses)
11. [Middleware in Controllers](#middleware-in-controllers)
12. [Error Handling in Controllers](#error-handling-in-controllers)
13. [How Controllers Are Dispatched](#how-controllers-are-dispatched)
14. [Controller Best Practices](#controller-best-practices)
15. [Testing Controllers](#testing-controllers)

---

## Controller Structure

Controllers are classes stored in the `App\Http\Controllers` namespace. Each method in a controller corresponds to a specific action or route, accepts a [`Request`](requests) object (followed by any route parameters), and returns a response.

A new project ships with an empty base controller at `app/Http/Controllers/Controller.php`:

```php
<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * Perform boilerplate for controllers here
     */
}
```

This base class is **app scaffolding, not a framework requirement** — the router (`Route::executeCallback()`) will happily dispatch to any class and method you point it at, with or without this base class in the inheritance chain. Extending it is a convention so you have one shared place to put cross-cutting helpers (response macros, shared authorization checks, etc.) for every controller in your app.

To return responses statically (`Response::view(...)`, `JsonResponse::ok(...)`), import the **facades** from `Eyika\Atom\Framework\Support\Facade`, not the raw HTTP classes.

### Example Controller
```php
<?php

namespace App\Http\Controllers;

use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = ['John Doe', 'Jane Smith', 'Alice Johnson'];
        return JsonResponse::ok('users list', $users);
    }

    public function show(Request $request, int $id)
    {
        $user = ['id' => $id, 'name' => 'John Doe'];
        return JsonResponse::ok('user found', $user);
    }
}
```

> `JsonResponse::ok()` takes a **message first, then the data**: `ok(string $message, mixed $data = [])`.

---

## Creating Controllers

To create a controller run:
```bash
php artisan make:controller ExampleController
```

Or to create an api controller
```bash
php artisan make:controller ExampleController --api
```

Or define a PHP class in the `App\Http\Controllers` directory. Each method corresponds to a specific action or endpoint.

> The `make:controller` scaffold generates `show`, `list`, `create`, `update`, and `delete` actions wired to `{Model}::getBuilder()` calls and wrapped in `try`/`catch` blocks for `PDOException` and `Exception`. It's a starting point, not a required shape — trim, rename, or restructure the generated methods freely.

### Naming Convention
- **Class Name**: Use PascalCase for class names (e.g., `UserController`, `PostController`).
- **Method Name**: Use camelCase for method names (e.g., `index`, `show`, `store`).

---

## Action Signatures

Every controller action receives the current [`Request`](requests) as its **first** parameter, followed by the route's dynamic segments as **positional** parameters, in the order they appear in the route URI:

```php
Route::get('/posts/{postId}/comments/{commentId}', [CommentController::class, 'show']);
```

```php
public function show(Request $request, string $postId, string $commentId)
{
    // $postId and $commentId are bound in URI order, not by name.
}
```

Route parameter values are always strings (they come straight off the URL) and are already URL-decoded — type-hint and cast (`(int) $id`) as needed inside your action.

> A parameter's **name** still matters for two things: [route model binding](#route-model-binding) (which key needed for the model lookup by matching the parameter *name*, not position) and reading it back off the request — see below.

### Reading route parameters from the Request

Besides the positional method arguments, every matched route parameter is also available on the request:

```php
public function show(Request $request)
{
    $id = $request->route_params['id'];   // explicit array access
    $id = $request->id;                   // magic __get — also checks route_params
}
```

`$request->{name}` resolves in this order: **request attributes, route params, input (body), then query string**.

Attributes come first deliberately — they are what trusted server-side code binds (`$request->tenant = $obj` in a middleware), whereas input and query come from the caller. Resolving them last, as earlier versions did, meant a client could shadow bound context simply by naming it in the request body.

> **Binding context in middleware?** Prefer the explicit API — `$request->setAttribute('tenant', $obj)` and `$request->attribute('tenant')`. These read and write only the attribute bag, so nothing in the request can shadow them:
>
> ```php
> $request->setAttribute('current_customer', $customer);   // in middleware
> $customer = $request->attribute('current_customer');     // in the handler
> ```
>
> `hasAttribute()` and `attributes()` round the API out.

### Optional parameters

Declare a matching default so an omitted `{id?}` segment doesn't error:

```php
Route::get('/posts/{id?}', [PostController::class, 'index']);
```

```php
public function index(Request $request, ?string $id = null)
{
    return $id ? "Post $id" : 'All posts';
}
```

See the [Routing](routing) docs for the full parameter/optional-segment syntax.

---

## Using Controllers in Routes

Controllers are mapped to routes in the `routes/api.php` or `routes/web.php` file (or any custom file loaded by a route map — see [Routing](routing)).

### Defining Routes with Controllers
```php
use Eyika\Atom\Framework\Http\Route;
use App\Http\Controllers\UserController;

// Example Routes
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show']);
```

The `[Controller::class, 'method']` array form is the only controller callback syntax the router understands — there's no `'Controller@method'` string shorthand.

---

## Invokable Controllers

For a controller that only ever does one thing, define a single `__invoke()` method and point the route at it the same way as any other action:

```php
<?php

namespace App\Http\Controllers;

use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\Response;

class HealthCheckController extends Controller
{
    public function __invoke(Request $request)
    {
        return Response::plain('ok');
    }
}
```

```php
use App\Http\Controllers\HealthCheckController;

Route::get('/health', [HealthCheckController::class, '__invoke']);
```

> Route callbacks are also matched with `is_callable()` before the `[Class, 'method']` array form, so a **closure** or an already-instantiated object with `__invoke()` works too (`Route::get('/health', new HealthCheckController)`). A bare class-name string (`Route::get('/health', HealthCheckController::class)`) does **not** work — the router treats a plain string callback as a file path to `include`, not a class to instantiate. Always use the `[Class::class, '__invoke']` (or `'method'`) array form.

---

## Resource Controllers

Resource controllers provide a standardized way to handle common CRUD operations. Atom does not ship a `Route::resource()` helper — there's no such method on `Route` — so you define resourceful routes manually. Use `Route::group()` with a string prefix to group them.

### Example: Defining a Resource Controller
```php
Route::group('users', function () {
    Route::get('/', [UserController::class, 'index']);          // List all users
    Route::get('/{id}', [UserController::class, 'show']);       // Show a user
    Route::post('/', [UserController::class, 'store']);         // Create a new user
    Route::put('/{id}', [UserController::class, 'update']);     // Update a user
    Route::delete('/{id}', [UserController::class, 'destroy']); // Delete a user
});
```

### Applying middleware to the whole resource

Stage middleware before the group with `Route::middleware(..., false)`, which applies to every route registered inside the group callback (see the "Group Middleware" section of [Routing](routing)):

```php
Route::middleware('auth', false)->group('users', function () {
    Route::get('/', [UserController::class, 'index']);
    Route::post('/', [UserController::class, 'store']);
    Route::delete('/{id}', [UserController::class, 'destroy']);
});
```

---

## Route Model Binding

If the `SubstituteBindings` middleware is in the route's middleware stack — it's included by default in both the `web` and `api` groups of the scaffolded `App\Http\Kernel` — a route parameter whose name matches the lowercased short class name of one of your `app/Models/*` classes is automatically swapped for a resolved model instance before your action runs:

```php
// App\Models\User exists, so {user} is bound to a User model.
Route::get('/users/{user}', [UserController::class, 'show']);
```

```php
use App\Models\User;

public function show(Request $request, User $user)
{
    // $user is already a resolved User model instance — no manual lookup needed.
    return JsonResponse::ok('user found', $user);
}
```

Behind the scenes this looks the row up by the model's **route key**. If no row matches, the middleware throws `Eyika\Atom\Framework\Exceptions\Db\ModelNotFoundException` **before your controller method is ever invoked** — the framework's exception handler turns that into a 404 JSON response for API/JSON requests, or a redirect back with errors for web requests (see [Error Handling in Controllers](#error-handling-in-controllers)).

### Choosing the column to bind against

By default the route key is the model's `primaryKey`, so `{user}` resolves via `find()`. Override `getRouteKeyName()` to bind a human-readable segment instead:

```php
class Post extends Model
{
    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
```

```php
// /posts/my-first-post  →  Post where slug = 'my-first-post'
Route::get('/posts/{post}', [PostController::class, 'show']);
```

This also covers models whose primary key is a UUID — no override needed, since the lookup follows the key rather than the shape of the value.

Notes and gotchas:
- Binding is keyed on the **route parameter name**, not the method parameter name — `{user}` looks for a model whose short class name lowercases to `user` (i.e. `App\Models\User`). Name your route segments to match your model.
- A parameter whose name matches **no** model (`{format}`, `{page}`) is left alone as a scalar.
- Pass parameter names to `SubstituteBindings::class` (or via a `bindings:` alias, if you register one) to exclude specific keys from binding — it accepts `...$ignoreKeys`.
- The model class map is built once per process by scanning `app/Models` and cached — new model classes added after that require a process/worker restart to be picked up. An app with no `app/Models` directory simply binds nothing.

> **Changed in this release.** Binding previously only considered parameters whose value passed `is_numeric()`, so slug and UUID segments silently reached the controller as raw strings — and a missing row was skipped rather than raising, so `ModelNotFoundException` never actually fired. Both are fixed. If your controllers defensively re-looked-up a model because binding "didn't work", that workaround can go.

---

## Constructors and Dependency Injection

> **Constructor arguments are *not* automatically resolved for controllers.** The router instantiates your controller with a bare `new $controller` — see [How Controllers Are Dispatched](#how-controllers-are-dispatched) — so a required, typed constructor parameter will fatal with an `ArgumentCountError` the moment that controller is dispatched.

The framework's service container **is** capable of autowiring class constructors (it's used internally to build the `Kernel`, `ExceptionHandler`, and other framework services), but that autowiring path is only exercised when something calls `App::make(...)` / `app()->make(...)` — the router never does this for controllers.

If a controller needs a service, resolve it yourself inside the constructor (or the action) rather than relying on the container to inject it:

```php
<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\App;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

class UserController extends Controller
{
    private UserService $userService;

    public function __construct()
    {
        // Resolved manually — the container is not consulted for you here.
        $this->userService = App::make(UserService::class);
        // Equivalent to: $this->userService = new UserService();
        // if UserService itself has no constructor dependencies.
    }

    public function index(Request $request)
    {
        $users = $this->userService->getAllUsers();
        return JsonResponse::ok('users list', $users);
    }
}
```

If `UserService` has no constructor dependencies of its own, plain `new UserService()` works just as well and avoids the container lookup entirely. Reach for `App::make()` when the service itself needs autowiring (nested class-typed constructor params) or when it's bound in the container as a singleton/interface and you want that specific instance.

---

## Handling Request Data

The `Request` object provides methods to retrieve and manipulate incoming request data. See the full [Requests](requests) docs for everything it offers (files, headers, JSON bodies, `expectsJson()`, etc.).

### Example: Retrieving Data from Requests
```php
use Eyika\Atom\Framework\Support\Validator;

public function store(Request $request)
{
    $data = $request->all();          // Get all input data
    $name = $request->input('name');  // Retrieve a specific field

    if (!$validated = $request->validate([
        'name' => 'required|string',
        'email' => 'required|email',
    ])) {
        return JsonResponse::badRequest('validation failed', Validator::$errors);
    }

    return JsonResponse::ok('created', $validated);
}
```

> Prefer `only()`/`except()` for mass assignment into a model create/update, rather than passing `all()` straight through — see the [Requests](requests) and [Models](database/models) docs.

---

## Returning Responses

Controllers in Atom typically return a response object. Import the `Response` and `JsonResponse` **facades** to build them statically. The full API — views, redirects, cookies, headers, downloads, proxying, content negotiation — is documented on the [Responses](responses) page; this is the subset you'll reach for most from a controller.

### JsonResponse
Use the `JsonResponse` facade to return JSON data. Every helper takes a message first, then optional data/errors.
```php
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

public function index()
{
    $data = ['greeting' => 'Hello, world!'];
    return JsonResponse::ok('message', $data);
}
```

Common `JsonResponse` helpers:
- `JsonResponse::ok(string $message, mixed $data = [])` — 200
- `JsonResponse::created(string $message = '', mixed $data = [])` — 201
- `JsonResponse::noContent()` — 204
- `JsonResponse::badRequest(string $message = '', array $errors = [])` — 400
- `JsonResponse::unauthorized(string $message = 'Unauthorized')` — 401
- `JsonResponse::paymentRequired(string $message = '', array $errors = [])` — 402
- `JsonResponse::forbidden(string $message = '', array $errors = [])` — 403
- `JsonResponse::notFound(string $message, mixed $data = null)` — 404
- `JsonResponse::conflict(string $message = '', array $errors = [])` — 409
- `JsonResponse::unprocessableEntity(string $message = 'unprocessable request', string $errors = '')` — 422
- `JsonResponse::tooManyRequests(string $message = 'Too many requests', int|null $retryAfter = null, array $errors = [])` — 429
- `JsonResponse::serverError(string $message = '')` — 500
- `JsonResponse::badGateway(string $message = '')` — 502
- `JsonResponse::serviceUnavailable(string $message = 'Service unavailable', int|null $retryAfter = null, array $errors = [])` — 503

### Views and Plain Responses
Use the `Response` facade for HTML views, plain text, and redirects.
```php
use Eyika\Atom\Framework\Support\Facade\Response;

public function home()
{
    return Response::view('index', ['name' => 'Ada']);
}

public function ping()
{
    return Response::plain('pong');
}
```

### File Downloads
Return file downloads with `Response::download()`.
```php
use Eyika\Atom\Framework\Support\Facade\Response;

public function download()
{
    return Response::download('/path/to/file.txt', 'downloaded-file.txt');
}
```

### Custom HTTP Status Codes
Most `JsonResponse` helpers already encode a status. For custom text/status use `Response::custom()` or `Response::html()`:
```php
public function notFound()
{
    return JsonResponse::notFound('Resource not found');
}
```

> A controller action should **return** the response object, not call `->send()` itself — the router sends it for you once your action (and any middleware) returns. See the "Response Lifecycle" section of [Responses](responses).

---

## Middleware in Controllers

Middleware is applied to routes and route groups (see the [Middleware](middleware) and [Routing](routing) docs), rather than from inside the controller. There's no `middleware()` method on the base `Controller` class to call from a constructor — attach middleware where the route is declared instead:

```php
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');
```

Or apply it to a whole group:

```php
Route::middleware('auth', function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/settings', [DashboardController::class, 'settings']);
});
```

---

## Error Handling in Controllers

Controllers should handle exceptions gracefully to ensure user-friendly error messages. `find()`-style query builder methods return a falsy value (not an exception) when nothing matches, so the usual pattern is an explicit check:

```php
use Eyika\Atom\Framework\Support\Facade\JsonResponse;
use App\Models\User;

public function show(Request $request, int $id)
{
    if (!$user = User::find($id)) {
        return JsonResponse::notFound('User not found');
    }

    return JsonResponse::ok('user found', $user);
}
```

If the route instead relies on [route model binding](#route-model-binding), a missing row throws `ModelNotFoundException` for you automatically — no `try`/`catch` needed in the action, since it never runs:

```php
use App\Models\User;

// SubstituteBindings (default in web/api groups) resolves {user} or throws.
public function show(Request $request, User $user)
{
    return JsonResponse::ok('user found', $user);
}
```

If you'd rather render your own response instead of the framework's default 404 rendering for that exception, drop `SubstituteBindings` from the route's middleware and fall back to the manual `find()` check shown above.

---

## How Controllers Are Dispatched

`Route::dispatch(Request $request)` is the framework's HTTP entry point (called once per request from `Server`/`Kernel`). At a high level it:

1. Strips the query string and trailing slash from the request URI, then walks the routes registered for the request's HTTP method (falling back to `ANY` routes) looking for the first match — static routes compare by exact string, dynamic routes (`{param}` segments) match segment-by-segment via a regex and populate `$request->route_params`.
2. Merges the app's default middleware with the matched route's own middleware stack and runs them, plus the route callback, through a `Pipeline`.
3. Resolves the route's callback via `executeCallback($callback, $request, $parameters)`, which dispatches based on the shape of `$callback`:
   - **`is_callable($callback)`** — closures, plain function names, and already-instantiated objects with `__invoke()` are called directly as `$callback($request, ...$parameters)`.
   - **`[Controller::class, 'method']` array** — the controller is instantiated with a bare `new $controller` (**no** constructor arguments — see [Constructors and Dependency Injection](#constructors-and-dependency-injection)), then `[$instance, 'method']` is called as `($request, ...$parameters)`.
   - **A plain string** — treated as a relative file path and `include_once`'d. This is an internal mechanism, not a `'Controller@method'` shorthand for actions.
   - Anything else throws `NotFoundHttpException`.
4. If no route matched at all, the pipeline's core handler falls through to the `/404` `ANY` route if one is registered, or a `NotFoundHttpException` otherwise (see the "Fallback / Not-Found Routes" section of [Routing](routing)).
5. Whatever your action returns is coerced into a response (a non-`BaseResponse` return value is wrapped with `Response::plain()`) and `->send()` is called on it.

Route parameters are always passed to the callback in URL segment order via `array_values($parameters)` — positional, not associative — which is why action signatures list them left-to-right matching the route pattern.

---

## Controller Best Practices

1. **Keep Controllers Thin**: Avoid adding too much logic in controllers. Use services or helper classes for complex operations.
2. **Standardize Responses**: Use the `Response` / `JsonResponse` facades for consistent output.
3. **Utilize Middleware**: Offload repetitive tasks like authentication or logging to middleware.
4. **Don't assume constructor DI**: since the router doesn't autowire controllers, keep constructors cheap (no required typed params) or resolve dependencies explicitly with `App::make()`.

---

## Testing Controllers

Controllers are best tested through the framework's integration test case, which dispatches fabricated requests through the full routing + middleware pipeline and returns a `TestResponse`. Helpers include `$this->get()`, `$this->post()`, and `$this->postJson()`:

```php
public function test_it_shows_a_user(): void
{
    $response = $this->get('/users/1');

    $this->assertSame(200, $response->status);
    $response->assertJsonFragment(['id' => 1]);
}
```

You can also invoke a controller method directly with an injectable `Request` (its source keys are `server`, `query`, `post`, `cookies`, `files`, `headers`, `rawBody`):
```php
$request = new Request(['post' => ['id' => 1]]);
$controller = new UserController();
$response = $controller->show($request, 1);
```

> Instantiating the controller directly (as above) bypasses the router entirely, so route-model-binding-via-middleware won't run — pass an already-resolved model, or `find()` it yourself, when testing a method typed to receive one.

---

## Conclusion

Controllers in Atom framework provide a robust and flexible way to handle requests. By following the guidelines above — keeping the base controller thin, returning responses via the facades, resolving your own constructor dependencies, and offloading cross-cutting concerns to middleware — you can ensure that your application remains scalable, maintainable, and adheres to best practices.