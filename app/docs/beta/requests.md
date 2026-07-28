# Requests In Atom

## Introduction

The `Request` class in the Atom framework provides an abstraction layer for handling HTTP requests. It allows you to retrieve input data, query parameters, headers, files, and more. The `Request` object is automatically passed to controller methods or middleware, ensuring a clean and consistent interface for accessing request data.

---

## Accessing the Request Object

The `Request` object is injected into controller methods or middleware, enabling seamless access to incoming data.

The constructor signature is `new Request(?array $source = null)`. When `$source` is `null` (the normal case) the request captures data from PHP's superglobals and `php://input`, so `new Request()` behaves as before. Passing an explicit source (keys: `server`, `query`, `post`, `cookies`, `files`, `headers`, `rawBody`) lets a worker or a test build a request without touching process globals.

### Example: Accessing the Request in a Controller
```php
<?php

namespace App\Http\Controllers;

use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

class UserController
{
    public function store(Request $request)
    {
        $data = $request->all();
        return JsonResponse::ok('', $data);
    }
}
```

> Static response calls (`JsonResponse::ok(...)`) go through the **facade** in `Eyika\Atom\Framework\Support\Facade`. See the [Controllers](controllers) docs for more.

---

## Retrieving Request Data

The `Request` object provides several methods to retrieve input data.

### Retrieving All Input Data
```php
$data = $request->all();
```

### Retrieving All Post Input Data
```php
$data = $request->input();
```

### Retrieving a Specific Post Input
```php
$name = $request->input('name'); // Returns null if 'name' is not provided
$email = $request->input('email', 'default@example.com'); // Default value
```

### Retrieving a Specific Query Input
```php
$name = $request->query('name'); // Returns null if 'name' is not provided
$email = $request->query('email', 'default@example.com'); // Default value
```

### Checking for an Input
```php
if ($request->has('name')) {
    // 'name' exists in the input
}
```

---

## Query Parameters

Query parameters from the URL can be accessed using similar methods.

### Example: Accessing Query Parameters
For a URL like `/users?active=true&page=2`:
```php
$active = $request->query('active'); // Returns 'true'
$page = $request->query('page', 1); // Returns '2'
```

---

## Headers

The `Request` object allows you to access HTTP headers.

### Example: Retrieving Headers
```php
$authToken = $request->header('Authorization');
```

You can also provide a default value:
```php
$authToken = $request->header('Authorization', 'Bearer default-token');
```

### Checking for a Header
```php
if ($request->hasHeader('Authorization')) {
    // The Authorization header exists
}
```

---

## File Uploads

The `Request` object makes it easy to handle uploaded files.

### Retrieving Uploaded Files
`file()` returns a `File` object (or `null` if the field is absent). `files()` returns all uploaded files, and `hasFile()` checks for one.
```php
$file = $request->file('avatar');
if ($file) {
    // store($path, $disk) uploads the file to the given storage disk
    $file->store('uploads/avatars', 'local');
}
```

### File Methods
The original upload metadata is available through `uploadProperties()`:
- `$file->uploadProperties()->name()`: Retrieve the original filename.
- `$file->uploadProperties()->type()`: Retrieve the MIME type.
- `$file->uploadProperties()->size()`: Retrieve the file size in bytes.
- `$file->uploadProperties()->tmpName()`: Retrieve the temporary upload path.

The `File` object itself provides storage operations:
- `$file->store($path, $disk)`: Upload the file to a storage disk.
- `$file->move($from, $to)`: Move the file to a new location.

---

## Validating Requests

The `Request` object includes validation utilities for ensuring the integrity of input data.

### Example: Validating Input
```php
use Eyika\Atom\Framework\Support\Validator;

$data = $request->validate([
    'name' => 'required|string',
    'email' => 'required|email',
    'age' => 'nullable|integer|min:18',
]);

if (!$data) {
    logger()->info('validation failed', Validator::$errors);
}
```

`validate()` returns the **validated data** (an array) on success, or `false` on failure — in which case the collected errors are available in `Validator::$errors` (or `$request->validationErrors()`). If you'd rather have validation throw automatically, use `validateOrFail()`:
```php
$data = $request->validateOrFail([
    'name' => 'required|string',
], message: 'errors in request', code: 422);
```

### Validation Rules
- **required**: Ensures the field is present.
- **sometimes**: Ensures the field could be present or not (If present other validation rules specified will apply).
- **forbidden**: Ensures the field cannot be present in the request.
- **string**: Checks if the field is a string.
- **email**: Validates email format.
- **integer**: Ensures the field is an integer.
- **min**: Checks the minimum value or length.
- **max**: Checks the maximum value or length.
- **contains**: Checks the string contains a string value.
- **not_contains**: Checks the string does not contain a string value.
- **in**: Checks the field is found in a given array.
- **not_in**: Checks the field is not found in a given array.
- **exists**: Checks the field exists in the given table and column on db.
- **not_exists**: Checks the field does not exists in the given table and column on db.
- **numeric**: Checks the field can be cast to a number.
- **url**: Checks the field is a valid url pattern.
- **file**: Checks the field is a file.
- **array**: Checks the field is an array data.
- **json**: Checks the field is a valid json string.

By default `validate()` does not throw — it returns `false` on failure. Use `validateOrFail()` if you want a failed validation to raise an exception that the framework turns into an error response.

---

## Request Methods

The `Request` class provides methods to check the HTTP method of the request.

### Checking the Request Method
```php
if ($request->isMethod('POST')) {
    // The request is a POST request
}
```

### Getting the Request Method
```php
$method = $request->method()
```

### Supported Methods
- `isMethod('GET')`
- `isMethod('POST')`
- `isMethod('PUT')`
- `isMethod('DELETE')`

---

## Working with JSON Data

The `Request` object handles JSON payloads sent in the body of a request automatically. When the `Content-Type` is `application/json`, the decoded body is merged into the request input, so the usual accessors work without any extra step:
```php
$data = $request->input();       // full decoded JSON body (plus files)
$name = $request->input('name'); // a specific key from the JSON body
```

You can check for a JSON request with `$request->isJson()`, and `$request->rawBody()` returns the raw request body string.

> A dedicated `$request->json()` accessor is not yet implemented (We'll Be Glad To Get A PR From You) — use `input()` as shown above.

---

## CSRF Protection

The framework verifies CSRF tokens for state-changing requests through the shipped `VerifyCsrfToken` middleware (add it to the `web` middleware group in your Kernel). Token generation and validation are handled by the `Csrf` helper rather than a method on the request:
```php
use Eyika\Atom\Framework\Http\Csrf;

Csrf::setCsrfToken();      // generate/refresh the token (e.g. before rendering a form)
$valid = Csrf::csrfIsValid(); // validate the incoming request's token
```

> `VerifyCsrfToken` exempts read-only verbs (GET/HEAD/OPTIONS) and verifies all state-changing ones (POST/PUT/PATCH/DELETE).

---

## Customizing the Request

You can extend the `Request` class to add custom behavior or methods.

### Example: Extending the Request
```php
<?php

namespace App\Http;

use Eyika\Atom\Framework\Http\Request as BaseRequest;

class Request extends BaseRequest
{
    public function isAdmin(): bool
    {
        return $this->input('role') === 'admin';
    }
}
```

> Note: Extending the request class may not really have automatic effect for now (We'll Be Glad To Get A PR From You)

---

## Testing Requests

Requests can be tested by simulating HTTP calls with mock data.

### Example: Simulating a Request
Pass an explicit source array. Body fields go under the `post` key; URL parameters under `query`:
```php
use Eyika\Atom\Framework\Http\Request;

$request = new Request([
    'post' => ['name' => 'John Doe', 'email' => 'john@example.com'],
]);
$name = $request->input('name'); // 'John Doe'
```

---

## Best Practices

1. **Sanitize Inputs**: Always validate and sanitize user inputs to avoid security vulnerabilities.
2. **Use Default Values**: Provide default values for optional inputs to prevent errors.
3. **Avoid Overloading Controllers**: Offload complex input handling to custom request classes or middleware.

---

## Conclusion

The `Request` object in Atom framework simplifies the process of working with HTTP requests, offering a rich set of utilities to access, validate, and manipulate incoming data. By leveraging these features, you can build secure, scalable, and maintainable applications.