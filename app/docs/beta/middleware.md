# Middlewares In Atom

## Introduction

Middleware in the Atom framework acts as a bridge between an HTTP request and a response. It provides a mechanism to inspect, modify, or reject incoming requests before they reach your application's core logic or outgoing responses before they are sent to the client.

Atom middleware operates using a `handle` method, which determines the flow of the request. Middleware is essential for common tasks such as authentication, authorization, logging, and data transformations.

---

## Middleware Structure

Middleware classes must implement the `MiddlewareInterface` from the Atom framework. This interface defines the `handle` method, which contains the logic for processing incoming requests.

### Sample Middleware Structure
```php
<?php

namespace App\Http\Middlewares;

use Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface;
use Eyika\Atom\Framework\Http\Request;

class SampleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request): bool
    {
        // Middleware logic here
        return false; // Return true to stop further request processing
    }
}
```

---

## Creating Middleware

Middleware can be created manually by defining a class that implements the `MiddlewareInterface` in the `App\Http\Middlewares` namespace.

### Example: `AuthMiddleware`
Below is an example of an `AuthMiddleware` that handles user authentication.

```php
<?php

namespace App\Http\Middlewares;

use Exception;
use Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface;
use Eyika\Atom\Framework\Http\JsonResponse;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Auth\Guard;
use PDOException;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle(Request $request): bool
    {
        try {
            // Attempt to authenticate the user
            if (!$user = Guard::tryToAuthenticate()) {
                return JsonResponse::unauthorized(); // Return an unauthorized response
            }

            // Fetch user details without protection
            $user = $user->find(is_protected: false);
            $request->auth_user = $user; // Attach authenticated user to the request
        } catch (PDOException $e) {
            consoleLog(0, $e->getMessage() . ' ' . $e->getTraceAsString());
            return JsonResponse::unauthorized();
        } catch (Exception $e) {
            consoleLog(0, $e->getMessage() . ' ' . $e->getTraceAsString());
            return JsonResponse::unauthorized();
        }
        return false; // Allow request to proceed
    }
}
```

---

## Using Middleware

Middleware can be applied to routes, route groups, or globally for all requests.

### Applying Middleware to Routes
Middleware can be assigned to specific routes using the `middleware` method.

```php
use Framework\Routing\Route;

Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');
```

### Middleware in Route Groups
Apply middleware to a group of routes:
```php
Route::middleware(AuthMiddlware::class, function () {
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::post('/settings', [SettingsController::class, 'update']);
});
```

### Global Middleware
Global middleware runs on every request. It can be registered in a dedicated configuration file or via a bootstrap method in the framework.

```php
// Example of registering global middleware
$router->middleware([
    'App\Http\Middlewares\CheckMaintenanceMode',
    'App\Http\Middlewares\LogRequests',
]);
```



---

## Middleware Return Values

Middleware should return either:
- **`false`**: To allow the request to proceed.
- **A response object**: To stop the request and send a response back to the client.

### Example: Blocking Unauthorized Users
```php
public function handle(Request $request): bool
{
    if (!$request->auth_user) {
        return JsonResponse::unauthorized();
    }
    return false;
}
```

---

## Registering Middleware

Middleware must be registered to be used in your application. This can be done in the framework's middleware registry or within a specific route or group.

### Example: Middleware Registry
```php
return [
    'auth' => App\Http\Middlewares\AuthMiddleware::class,
    'admin' => App\Http\Middlewares\AdminMiddleware::class,
];
```

> Note: This is not yet implemented (We'll Be Glad To Get A PR From You)

---

## Error Handling in Middleware

Middleware should handle exceptions gracefully to avoid application crashes. Use try-catch blocks to manage errors effectively.

### Example
```php
public function handle(Request $request): bool
{
    try {
        // Logic
    } catch (PDOException $e) {
        consoleLog(0, $e->getMessage());
        return JsonResponse::serverError();
    } catch (Exception $e) {
        consoleLog(0, $e->getMessage());
        return JsonResponse::serverError();
    }
    return false;
}
```

---

## Testing Middleware

Middleware can be tested independently by creating mock requests and validating their behavior.

### Example Test
```php
$middleware = new AuthMiddleware();
$request = new Request();

// Mock an unauthenticated user
$response = $middleware->handle($request);
assert($response instanceof JsonResponse && $response->status === 401);
```

---

## Conclusion

Middleware in Atom is a powerful feature that provides fine-grained control over your application's request and response cycle. By implementing the `MiddlewareInterface`, you can create reusable, testable components that enhance your application's functionality and security. 

For additional features and advanced middleware capabilities, consult the framework’s extended documentation or source code.