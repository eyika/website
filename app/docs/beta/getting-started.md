# Getting Started

Welcome to **Atom**! This guide will walk you through the basic setup and help you get started quickly with building your application.

## Prerequisites

Before you begin, ensure that your environment meets the following requirements:

- PHP >= 7.4 (PHP 8.x fully supported)
- Composer (for managing dependencies)
- A supported database system (e.g., MySQL, SQLite)

## Installation

### Step 1: Create Your Project

To scaffold a new **Atom** application, run the command below in your terminal.
```bash
composer create-project eyika/atom my-app dev-main
```

> Note: include `dev-main` for now — the skeleton is published at `beta` stability and does not yet have a tagged version number.

This pulls in the `eyika/atom-framework` core library plus the app template (controllers, providers, routes, config).

### Step 2: Navigate To Your Project Folder

```bash
cd my-app
```

### Step 3: Install Composer Dependencies

`composer create-project` already installs dependencies. If you cloned the template manually instead, run:

```bash
composer install
```

> If everything installs successfully, you should have a `vendor` folder in your project root.

## Starting Your Application

After installing the framework, you can start building your application.

### Step 1: Setup Your Environment

Copy the `.env.example` file to `.env`:

```bash
cp .env.example .env
```

Generate the `APP_KEY` environment variable (used by the encrypter, signed URLs and cookie encryption):

```bash
php artisan key:generate
```

> During `composer create-project` both steps above are usually run for you automatically (`.env` is copied and `key:generate` is invoked by the post-create script).

Edit the `.env` file to configure your environment settings, including the database connection, redis connection, mail, filesystem, etc.

### Step 2: Start the Development Server

You can start the development server using Atom's built-in server:

```bash
php artisan serve
```

or

```bash
php artisan serve --host=example.local --port=81
```

> The built-in server binds to `0.0.0.0` on port `80` by default. Use `--host`/`--port` to change this — the host name must be resolvable (declared in your operating system's hosts file if it is not `localhost`).

Visit `http://localhost` (or `http://example.local:81` for the custom example above) in your browser to verify that the framework is working.

### Step 3: Creating Routes

Routes are declared in the files under `routes/`. `routes/web.php` holds session-backed web routes and `routes/api.php` holds stateless JSON routes. A route handler may return a string, an array, or a `Response`/`JsonResponse`. Here's an example in `routes/web.php`:

```php
use Eyika\Atom\Framework\Http\Route;

Route::get('/', function () {
    return 'Hello, World!';
});
```

Which route file handles a request is decided by `app/Providers/RouteServiceProvider`, which maps requests to route files (JSON/AJAX/`/api` requests go to `routes/api.php`; everything else falls back to `routes/web.php`). See [Routing](routing) for the full map/matcher API.

### Step 4: Create a Controller

To organize your application logic, use controllers. You can create one with:

```bash
php artisan make:controller HelloController
```

Or this to make an API controller (generated under `app/Http/Controllers/Api/`):

```bash
php artisan make:controller HelloController --api
```

A controller receives the `Request` (plus any route parameters) and returns a response. Using the `Response`/`JsonResponse` facades gives you static, expressive response builders:

```php
namespace App\Http\Controllers;

use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Facade\Response;

class HelloController extends Controller
{
    public function index(Request $request, string $name)
    {
        return Response::view('index', ['name' => $name]);
    }
}
```

Now point a route at the controller in `routes/web.php` or `routes/api.php`:

```php
use App\Http\Controllers\HelloController;
use Eyika\Atom\Framework\Http\Route;

Route::get('/name/{name}', [HelloController::class, 'index']);
```

This will return the `index` method's output when you visit `/name/{something}`.

## Project Structure

A freshly created app is organized like this (framework internals live in `vendor/eyika/atom-framework`; the directories below are yours to edit):

```
my-app/
├── app/
│   ├── Console/            # Console command + job classes, Console\Kernel
│   ├── Exceptions/         # Handler.php — app exception handling
│   ├── Http/
│   │   ├── Controllers/    # Web + Api controllers
│   │   ├── Middlewares/    # App-level middleware (TrimStrings, HandleCors, …)
│   │   └── Kernel.php      # Global middleware, groups, aliases, priority
│   ├── Mail/               # Mailable classes
│   ├── Models/             # Eloquent-style models
│   └── Providers/          # App-owned service providers (see below)
├── bootstrap/app.php       # Creates + returns the Application instance
├── config/                 # app.php, database.php, cache.php, mail.php, …
├── database/
│   ├── migrations/
│   └── seeders/
├── public/index.php        # HTTP entry point
├── resources/views/        # Blade templates
├── routes/                 # web.php, api.php, console.php
├── storage/                # Logs, cache, sessions, compiled views
├── tests/                  # Your test suite
└── artisan                 # CLI entry point
```

Note that `app/Providers/` now exists and is **app-owned**. The framework no longer hardcodes any `\App\Providers\*` class — your providers are listed in `config/app.php` under `'providers'` and boot on every request. A new app ships `Cache`, `Route`, `Console`, `Event`, `View`, `Database` and `App` service providers. See [Configuration](configuration) for how the provider list is registered.

## What's Next?

- Learn more about [Routing](routing) to define custom routes and route maps.
- Explore [Middleware](middleware) to handle requests before they reach your controllers.
- Dive into [Views](views) for rendering dynamic content.
