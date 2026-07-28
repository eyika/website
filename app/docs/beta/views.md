# Views In Atom

## Introduction

Views in the Atom framework allow you to separate the presentation layer from the application logic. They are responsible for rendering HTML or other content and are typically used in conjunction with controllers to build dynamic, interactive applications.

Atom ships with two templating engines and lets you choose between them with a single config flag:

- **Blade** (the *advance engine*, built on `eftec/bladeone`) — the full-featured default. Supports `@if`, `@foreach`, `@extends`, `@section`, components, and Atom's own directives such as `@csrf_token`.
- **A lightweight Twig-like engine** — a minimal compiler for projects that don't need Blade. It uses `{% ... %}` tags and `{{ ... }}` echoes.

Which engine runs is controlled by `config('view.use_advance_engine')` (env `USE_ADVANCE_ENGINE`, default `true`). When it is `true`, Blade renders your views; when `false`, the Twig-like engine is used.

---

## Creating a View

View files are stored in the paths listed in `config('view.paths')` — by default `resource_path('views')` (i.e. `resources/views`). Files use a `.blade.php` extension for both engines and contain a mix of HTML and PHP/template code. Compiled output is written to `config('view.compiled')`, which defaults to `storage/framework/views`.

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
    <p>Hello, {{ $name ?? 'Guest' }}!</p>
</body>
</html>
```

---

## Compiling a View

To compile a view to an HTML string (without immediately returning a response), use the `view()` helper. It resolves the template, runs it through the active engine, and returns the rendered markup.

### Example: Compiling a View
```php
use Eyika\Atom\Framework\Support\Facade\Response;

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

> The `view()` helper also resolves namespaced package views. A name like `pkg::name` is looked up against the directories a package registered via `ServiceProvider::loadViewsFrom()`, falling back to your app's view paths.

## Rendering a View

To render a view directly as the HTTP response, use `Response::view()` (via the `Response` facade) or the `response()->view()` helper. The view is compiled when the response is sent.

### Example: Rendering a View
```php
use Eyika\Atom\Framework\Support\Facade\Response;

class WelcomeController
{
    public function index(): Response
    {
        return Response::view('welcome', ['name' => 'John Doe']);
    }
}
```

The equivalent helper form is:
```php
return response()->view('welcome', ['name' => 'John Doe']);
```

In the examples above:
- The first parameter of `view()` is the name of the view file (without the `.blade.php` extension).
- The second parameter is an array of data to pass to the view.

---

## Passing Data to Views

Data is passed to a view as an associative array. Each key becomes a variable inside the template.

```php
return response()->view('profile', [
    'username' => 'johndoe',
    'age' => 30,
]);
```

### Accessing Data in Views
Inside the view, access the data using the engine's variable syntax:
```php
<p>Username: {{ $username }}</p>
<p>Age: {{ $age }}</p>
```

### Attaching errors and old input

A view response can be enriched with validation errors and previously submitted input, which the Blade engine exposes to `@errors` and `@old`:

```php
return response()->view('profile')
    ->withErrors(['username' => 'Name is required'])
    ->withValidationErrors($validator->errors())
    ->withInputs();
```

---

## Blade Syntax (advance engine)

When the advance engine is active, Atom compiles standard Blade directives.

### 1. Echoing Variables
```php
{{ $variable }}
```

This escapes output for security (equivalent to `htmlspecialchars`).

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

### 4. Atom-specific directives
Atom's `Blade` class adds a few directives on top of BladeOne:

```php
<form method="POST" action="/profile">
    @csrf_token          {{-- injects a hidden CSRF token field --}}
    <input name="email" value="@old('email')">
</form>

@errors('email')
    <span class="error">{{ $message }}</span>
@enderrors
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

Blade allows you to define layouts and extend them in specific views.

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

## The Twig-like Engine

When `config('view.use_advance_engine')` is `false`, the lightweight engine compiles a small Twig-inspired syntax instead of Blade:

```php
{% extends 'layouts/main.blade.php' %}

{% block content %}
    <h1>Hello, {{ user.name }}</h1>   {# dots compile to -> #}
    {{{ $rawHtml }}}                   {# triple braces escape output #}
{% endblock %}

{% yield content %}
```

- `{% ... %}` compiles to raw PHP.
- `{{ ... }}` echoes a value (a `.` is rewritten to `->`).
- `{{{ ... }}}` echoes an HTML-escaped value.
- `{% block %}` / `{% yield %}` / `{% extends %}` / `{% include %}` handle template composition.

---

## Escaping Data

By default, the `{{ }}` syntax escapes data for security. If you want to output raw data with Blade, use the `{!! !!}` syntax.

### Example: Escaping vs. Raw Output
```php
<p>Escaped: {{ $content }}</p>
<p>Raw: {!! $content !!}</p>
```

**Warning:** Use raw output sparingly and only when you are sure the content is safe.

---

## Handling Errors in Views

If a variable is missing, PHP will emit a warning. To avoid this, use the null coalescing operator.

### Example: Handling Undefined Variables
```php
<p>{{ $username ?? 'Guest' }}</p>
```

---

## Caching Views

Compiled templates are cached to `config('view.compiled')` (`storage/framework/views` by default) so they are not re-parsed on every request. Blade's compile mode is driven by `config('view.mode')` (env `VIEW_MODE`): in `local` it defaults to debug mode, otherwise auto mode is used.

---

## Binding the Engines

The `ViewServiceProvider` registers both engines as singletons and binds them into the container:

```php
$this->app->instance('view.blade', $this->app->make(Blade::class));
$this->app->instance('view.twig', $this->app->make(Twig::class));
```

You can resolve them directly with `app('view.blade')` or `app('view.twig')` when you need low-level access.

---

## Best Practices

1. **Keep Views Simple**: Avoid putting complex logic in views. Use controllers or service classes for logic.
2. **Reuse Components**: Break down views into reusable components or partials.
3. **Sanitize Output**: Always escape user-provided data to prevent XSS attacks.

---

## Testing Views

You can test views by dispatching a request through the integration test pipeline and asserting on the rendered body.

### Example: Testing a View
```php
$response = $this->get('/home');
$response->assertBodyContains('Welcome to the Home Page');
```

---

## Conclusion

The Atom framework's view system is designed to be simple yet powerful, enabling developers to build dynamic and maintainable user interfaces. By leveraging reusable components, layouts, and clean syntax, you can create a robust presentation layer for your applications.
