# Routing in Atom

## Introduction
The routing system in Atom is designed to be robust and flexible, allowing developers to define routes that map HTTP requests to specific actions within their application. The router supports dynamic route parameters, named routes, route groups, middleware, and route constraints, making it easy to build modern, scalable web applications.

---

## Defining Routes

Routes in Atom are defined in the `routes/web.php` or `routes/api.php` files (or any custom route file, depending on your application's structure). Each route is associated with an HTTP method and a callback or controller action.

### Example
```php
use Eyika\Atom\Framework\Http\Route;

Route::get('/home', function () {
    return 'Welcome to the Home Page';
});

Route::post('/submit', [FormController::class, 'submit']);
```

### Supported HTTP Methods
The routing system supports the following HTTP methods:
- `GET`
- `POST`
- `PUT`
- `PATCH`
- `DELETE`
- `OPTIONS`

For multiple methods, use:
```php
Route::match(['get', 'post'], '/form', [FormController::class, 'handle']);
```

> Note: Route::match is not yet implemented (We'll Be Glad To Get A PR From You)

For all methods:
```php
Route::any('/endpoint', [SomeController::class, 'anyMethod']);
```

---

## Dynamic Route Parameters

You can define dynamic segments in your routes using curly braces `{}`.

### Example
```php
Route::get('/user/{id}', function ($id) {
    return "User ID: $id";
});
```

### Optional Parameters
Optional parameters are specified with a `?`:
```php
Route::get('/post/{id?}', function ($id = null) {
    return $id ? "Post ID: $id" : "No Post ID provided";
});
```

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

Route groups allow you to apply common attributes to multiple routes.

### Example
```php
Route::group('admin', function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/settings', [AdminController::class, 'settings']);
});
```

Route groups allow you to apply common attributes, such as middleware or namespace, to multiple routes.

### Example
```php
Route::middleware(SomeMiddleware::class, false)->group('admin', function () {
    Route::get('/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/settings', [AdminController::class, 'settings']);
});
```

### Supported Group Options
- **prefix**: Adds a URI prefix to all routes in the group.
- **middleware**: Assigns middleware to routes.

---

## Middleware

Middleware can be applied to routes or route groups to filter HTTP requests.
```php
Route::get('/dashboard', [DashboardController::class, 'index'])->middleware('auth');
```

---

## Route Constraints

Define constraints for route parameters to ensure they match specific patterns.

### Example
```php
Route::get('/user/{id}', [UserController::class, 'show'])->where('id', '[0-9]+');
```

### Global Constraints
Global constraints can be defined in the router:
```php
Route::pattern('id', '[0-9]+');
```

> Note: This Constraint is not yet implemented (We'll Be Glad To Get A PR From You)

---

## Fallback Routes

Define a fallback route to handle unmatched requests:
```php
Route::fallback(function () {
    return 'Page Not Found';
});
```

> Note: This Fallback is not yet implemented (We'll Be Glad To Get A PR From You)

---

## Advanced Usage

### Route Prefixing
You can prefix routes to group them under a common URI segment:
```php
Route::prefix('api')->group(function () {
    Route::get('/users', [ApiController::class, 'users']);
});
```

> Note: This Prefixing is not yet implemented (We'll Be Glad To Get A PR From You)

### Custom HTTP Verbs
You can define custom HTTP verbs for routes:
```php
Route::custom('CUSTOM', '/custom-endpoint', [CustomController::class, 'handle']);
```

> Note: This Custom HTTP Verbs is not yet implemented (We'll Be Glad To Get A PR From You)

### Route Macros
Add custom functionality to the router:
```php
Route::macro('custom', function ($uri, $callback) {
    return Route::addRoute('CUSTOM', $uri, $callback);
});
```

> Note: This Macro is not yet implemented (We'll Be Glad To Get A PR From You)

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

## Conclusion

The routing system in Atom is designed to be intuitive and powerful. With features like dynamic parameters, named routes, middleware, and route groups, you can build scalable and maintainable web applications effortlessly.

For more advanced features and customization, refer to the advanced topics section or explore the framework's source code.