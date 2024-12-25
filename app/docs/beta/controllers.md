# Controllers In Atom Framework

## Introduction

Controllers in Atom framework serve as the entry point for handling HTTP requests. They contain the logic to process incoming requests, interact with models, and return appropriate responses to the client. Controllers ensure that your application follows the **MVC (Model-View-Controller)** pattern, separating concerns and improving code maintainability.

---

## Controller Structure

Controllers are classes stored in the `App\Http\Controllers` namespace. Each method in a controller corresponds to a specific action or route. A controller method typically accepts a `Request` object and returns a response.

### Example Controller
```php
<?php

namespace App\Http\Controllers;

use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Http\JsonResponse;

class UserController
{
    public function index(Request $request): JsonResponse
    {
        $users = ['John Doe', 'Jane Smith', 'Alice Johnson'];
        return JsonResponse::ok($users);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = ['id' => $id, 'name' => 'John Doe'];
        return JsonResponse::ok($user);
    }
}
```

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
use Framework\Routing\Route;
use App\Http\Controllers\UserController;

// Example Routes
Route::get('/users', [UserController::class, 'index']);
Route::get('/users/{id}', [UserController::class, 'show']);
```

---

## Resource Controllers

Resource controllers provide a standardized way to handle common CRUD operations. Atom does not enforce a specific structure but allows you to define resourceful routes manually.

### Example: Defining a Resource Controller
```php
Route::group(['prefix' => 'users'], function () {
    Route::get('/', [UserController::class, 'index']);    // List all users
    Route::get('/{id}', [UserController::class, 'show']); // Show a user
    Route::post('/', [UserController::class, 'store']);   // Create a new user
    Route::put('/{id}', [UserController::class, 'update']); // Update a user
    Route::delete('/{id}', [UserController::class, 'destroy']); // Delete a user
});
```

---

## Dependency Injection in Controllers

Controllers in Atom support dependency injection to streamline working with other classes or services.

### Example: Injecting Services
```php
<?php

namespace App\Http\Controllers;

use App\Services\UserService;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Http\JsonResponse;

class UserController
{
    private UserService $userService;

    public function __construct(UserService $userService)
    {
        $this->userService = $userService;
    }

    public function index(Request $request): JsonResponse
    {
        $users = $this->userService->getAllUsers();
        return JsonResponse::ok($users);
    }
}
```

In this example, the `UserService` class is automatically resolved by the service container.

---

## Handling Request Data

The `Request` object provides methods to retrieve and manipulate incoming request data.

### Example: Retrieving Data from Requests
```php
public function store(Request $request): JsonResponse
{
    $data = $request->all(); // Get all input data
    $name = $request->input('name'); // Retrieve a specific field
    if(!$validated = $request->validate([
        'name' => 'required|string',
        'email' => 'required|email',
    ])) {
        return JsonResponse::badRequest('message', Validator::$errors);
    }

    return JsonResponse::ok($validated);
}
```

---

## Returning Responses

Controllers in Atom typically return a response object. The framework provides several response utilities to standardize output.

### JsonResponse
Use the `JsonResponse` class to return JSON data.
```php
public function index(): JsonResponse
{
    $data = ['message' => 'Hello, world!'];
    return JsonResponse::ok('message', $data);
}
```

### FileResponse
Return file downloads using the `FileResponse` utility.
```php
use Eyika\Atom\Framework\Http\FileResponse;

public function download(): FileResponse
{
    return new FileResponse('/path/to/file.txt', 'downloaded-file.txt');
}
```

### Custom HTTP Status Codes
You can customize the status code for responses.
```php
public function notFound(): JsonResponse
{
    return JsonResponse::notFound('Resource not found');
}
```

---

## Middleware in Controllers

Middleware can be applied directly to controller methods.

### Applying Middleware
```php
use App\Http\Middlewares\AuthMiddleware;

class UserController
{
    public function __construct()
    {
        $this->middleware(AuthMiddleware::class);
    }

    public function index(Request $request): JsonResponse
    {
        return JsonResponse::ok('Welcome, authenticated user!');
    }
}
```

---

## Error Handling in Controllers

Controllers should handle exceptions gracefully to ensure user-friendly error messages.

### Example: Catching Exceptions
```php
public function show(Request $request, int $id): JsonResponse
{
    try {
        $user = User::findOrFail($id);
        return JsonResponse::ok('message', $user);
    } catch (ModelNotFoundException $e) {
        return JsonResponse::notFound('User not found');
    }
}
```

---

## Controller Best Practices

1. **Keep Controllers Thin**: Avoid adding too much logic in controllers. Use services or helper classes for complex operations.
2. **Standardize Responses**: Use the provided response utilities for consistent output.
3. **Utilize Middleware**: Offload repetitive tasks like authentication or logging to middleware.

---

## Testing Controllers

Controllers can be tested by simulating HTTP requests and asserting responses.

### Example Test
```php
$request = new Request(['id' => 1]);
$controller = new UserController();
$response = $controller->show($request, 1);

assert($response->status === 200);
assert($response->data['id'] === 1);
```

---

## Conclusion

Controllers in Atom framework provide a robust and flexible way to handle requests. By following the guidelines above, you can ensure that your application remains scalable, maintainable, and adheres to best practices.