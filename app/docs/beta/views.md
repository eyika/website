# Views In Atom

## Introduction

Views in the Atom framework allow you to separate the presentation layer from the application logic. They are responsible for rendering HTML or other content and are typically used in conjunction with controllers to build dynamic, interactive applications.

The Atom framework provides a simple and flexible blade-like or twig-like templating engine that supports variables, loops, conditionals, and more.

---

## Creating a View

View files in the Atom framework are stored in the `resources/views` directory by default. These files typically have a `.blade.php` extension and contain a mix of HTML and PHP code.

### Example: Basic View
```php
<!-- resources/views/welcome.blade.php -->
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome</title>
</head>
<body>
    <h1>Welcome to Atom Framework</h1>
    <p>Hello, <?= $name ?? 'Guest'; ?>!</p>
</body>
</html>
```

---

## Compiling a View

To compile a view from a controller or route, use the `view()` function.

### Example: Compiling a View
```php
use Eyika\Atom\Framework\Http\Response;

class WelcomeController
{
    public function index(): Response
    {
        $html = view('welcome', ['name' => 'John Doe']);
        logger()->info($html);
        return Response::html($html);
    }
}
```

> Note: Response::html() feature is not yet implemented (We'll Be Glad To Get A PR From You)

## Rendering a View

To render a view from a controller or route, use the `response()->view()` function.

### Example: Rendering a View
```php
use Eyika\Atom\Framework\Http\Response;

class WelcomeController
{
    public function index(): Response
    {
        return response()->view('welcome', ['name' => 'John Doe']);
    }
}
```

In the example above:
- The first parameter of `view()` is the name of the view file (without the `.blade.php` extension).
- The second parameter is an array of data to pass to the view.

---

## Passing Data to Views

You can pass data to a view in two ways:

### 1. Passing an Associative Array
```php
return response()->view('profile', [
    'username' => 'johndoe',
    'age' => 30,
]);
```

### 2. Using `with` Method
```php
return response()->view('profile')
    ->with('username', 'johndoe')
    ->with('age', 30);
```

### Accessing Data in Views
Inside the view, you can access the data using blades's or twigs variable syntax:
```php
<p>Username: {{ $username; }}</p>
<p>Age: {{ $age; }}</p>
```

---

## Blade-Like Syntax

Atom supports a simplified templating syntax similar to Blade for common tasks like loops, conditionals, and more.

### 1. Echoing Variables
```php
{{ $variable }}
```

This is equivalent to:
```php
<?= htmlspecialchars($variable, ENT_QUOTES, 'UTF-8'); ?>
```

### 2. Conditionals
```php
@if ($user)
    <p>Welcome, {{ $user->name }}</p>
@else
    <p>Welcome, Guest</p>
@endif
```

### 3. Loops
```php
@foreach ($items as $item)
    <li>{{ $item }}</li>
@endforeach
```

---

## Including Partial Views

To include one view inside another, use the `@include` directive.

### Example: Including a Partial View
```php
<!-- resources/views/header.blade.php -->
<header>
    <h1>My Website</h1>
</header>

<!-- resources/views/home.blade.php -->
@include('header')

<p>Welcome to the homepage!</p>
```

---

## Extending Layouts

Atom's templating engine allows you to define layouts and extend them in specific views.

### Example: Defining a Layout
```php
<!-- resources/views/layouts/main.blade.php -->
<!DOCTYPE html>
<html>
<head>
    <title>@yield('title', 'Default Title')</title>
</head>
<body>
    @yield('content')
</body>
</html>
```

### Example: Extending a Layout
```php
<!-- resources/views/home.blade.php -->
@extends('layouts.main')

@section('title', 'Home Page')

@section('content')
    <h1>Welcome to the Home Page</h1>
    <p>This is some content.</p>
@endsection
```

---

## Escaping Data

By default, the `{{ }}` syntax escapes data for security. If you want to output raw data, use the `{!! !!}` syntax.

### Example: Escaping vs. Raw Output
```php
<p>Escaped: {{ $content }}</p>
<p>Raw: {!! $content !!}</p>
```

**Warning:** Use raw output sparingly and only when you are sure the content is safe.

---

## Handling Errors in Views

If a variable or method is missing, the framework will throw an exception. To avoid this, use the null coalescing operator.

### Example: Handling Undefined Variables
```php
<p>{{ $username ?? 'Guest' }}</p>
```

---

## Caching Views

The framework can cache compiled views to improve performance. View caching is automatically enabled in production.

---

## Best Practices

1. **Keep Views Simple**: Avoid putting complex logic in views. Use controllers or service classes for logic.
2. **Reuse Components**: Break down views into reusable components or partials.
3. **Sanitize Output**: Always escape user-provided data to prevent XSS attacks.

---

## Testing Views

You can test views by simulating HTTP requests and asserting the rendered output.

### Example: Testing a View
```php
$response = $this->get('/home');
$response->assertSee('Welcome to the Home Page');
```

---

## Conclusion

The Atom framework's view system is designed to be simple yet powerful, enabling developers to build dynamic and maintainable user interfaces. By leveraging reusable components, layouts, and clean syntax, you can create a robust presentation layer for your applications.