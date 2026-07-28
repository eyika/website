# Controllers In Atom Framework

## Introduction

Controllers in Atom framework serve as the entry point for handling HTTP requests. They contain the logic to process incoming requests, interact with models, and return appropriate responses to the client. Controllers ensure that your application follows the **MVC (Model-View-Controller)** pattern, separating concerns and improving code maintainability.

---

## Controller Structure

Controllers are classes stored in the `App\Http\Controllers` namespace and typically extend the app's base `App\Http\Controllers\Controller` class. Each method in a controller corresponds to a specific action or route, accepts a `Request` object (followed by any route parameters), and returns a response.

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

### Naming Convention
- **Class Name**: Use PascalCase for class names (e.g., `UserController`, `PostController`).
- **Method Name**: Use camelCase for method names (e.g., `index`, `show`, `store`).

---

## Using Controllers in Routes

Controllers are mapped to routes in the `routes/api.php` or `routes/web.php` file.

### Defining Routes with Controllers
```php
use Eyika\Atom\Framework\Http\Route;
use App\Http\Controllers\UserController;

// Example Routes
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show']);
```

---

## Resource Controllers

Resource controllers provide a standardized way to handle common CRUD operations. Atom does not enforce a specific structure but allows you to define resourceful routes manually. Use `Route::group()` with a string prefix to group them.

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

---

## Dependency Injection in Controllers

Controllers in Atom support dependency injection to streamline working with other classes or services. Constructor dependencies are resolved automatically by the service container.

### Example: Injecting Services
```php
<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

class UserController extends Controller
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request)
    {
        $users = $this->userService->getAllUsers();
        return JsonResponse::ok('users list', $users);
    }
}
```

In this example, the `UserService` class is automatically resolved by the service container.

---

## Handling Request Data

The `Request` object provides methods to retrieve and manipulate incoming request data.

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

---

## Returning Responses

Controllers in Atom typically return a response object. Import the `Response` and `JsonResponse` **facades** to build them statically.

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
- `JsonResponse::serverError(string $message = '')` — 500

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

---

## Middleware in Controllers

Middleware is applied to routes and route groups (see the [Middleware](middleware) and [Routing](routing) docs), rather than from inside the controller constructor. Attach it where the route is declared:

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

Controllers should handle exceptions gracefully to ensure user-friendly error messages.

### Example: Catching Exceptions
```php
use Eyika\Atom\Framework\Support\Facade\JsonResponse;
use Eyika\Atom\Framework\Exceptions\Db\ModelNotFoundException;

public function show(Request $request, int $id)
{
    try {
        $user = User::findOrFail($id);
        return JsonResponse::ok('user found', $user);
    } catch (ModelNotFoundException $e) {
        return JsonResponse::notFound('User not found');
    }
}
```

---

## Controller Best Practices

1. **Keep Controllers Thin**: Avoid adding too much logic in controllers. Use services or helper classes for complex operations.
2. **Standardize Responses**: Use the `Response` / `JsonResponse` facades for consistent output.
3. **Utilize Middleware**: Offload repetitive tasks like authentication or logging to middleware.

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

---

## Conclusion

Controllers in Atom framework provide a robust and flexible way to handle requests. By following the guidelines above — extending the base controller, returning responses via the facades, and offloading cross-cutting concerns to middleware — you can ensure that your application remains scalable, maintainable, and adheres to best practices.
