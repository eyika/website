# Getting Started

Welcome to **Atom**! This guide will walk you through the basic setup and help you get started quickly with building your application.

## Prerequisites

Before you begin, ensure that your environment meets the following requirements:

- PHP >= 7.4
- Composer (for managing dependencies)
- A supported database system (e.g., MySQL, SQLite)

## Installation

### Step 1: Create Your Project From Source

To install **Atom**, run the command below in your terminal.
```bash
composer create-project eyika/atom my-app dev-main
```

> Note: you must include 'dev-main' for now as there is no version number yet.

### Step 2: Navigate To Your Project Folder

```bash
cd my-app
```

### Step 3: Install Composer Dependencies

```bash
composer install
```

> If everything installs successfully, you should have `vendor` folder in your project root folder.

## Starting You Application

After installing the framework, you can start building your application.

### Step 1: Setup Your Environment

Copy the `.env.example` file to `.env`:

```bash
cp .env.example .env
```

Generate the APP-KEY env variable

```bash
php artisan key:generate
```

> During installation the previous steps may already be done for you automatically

Edit the `.env` file to configure your environment settings, including the database connection, redis connection, file etc.

### Step 2: Start the Development Server

You can start the development server using Atom's built-in server:

```bash
php artisan serve
```

or

```bash
php artisan serve --host=example.local --port=81
```

> You can change the host and port values to your need as long as the host name
> has been declared in the operating systems host file

Visit `http://localhost:8000` or `http://localhost:81` in your browser to verify that the framework is working.

### Step 3: Creating Routes

By default, the framework will route HTTP requests to the appropriate controller and action. Here's an example of defining a route in `routes/web.php`:

```php
Route::get('/', function () {
    echo 'Hello, World!';
    return true;
});
```

### Step 4: Create a Controller

To organize your application logic, it's a good idea to use controllers. You can create a controller using the following command:

```bash
php artisan make:controller HomeController
```

Or this to make Api Controller

```bash
php artisan make:controller HomeController --api
```

Then, define a method in the controller:

```php
class HomeController {
    public function index() {
        echo 'Welcome to your application!';
        return true;
    }
}
```

Now, modify your `routes/web.php` or `routes/api.php` to use the controller:

```php
Route::get('/', [HomeController::class, 'index']);
```

Or

```php
$router = new Eyika\Atom\Framework\Http\Route();
$router->get('/', [HomeController::class, 'index']);
```

This will return the `index` method's output when you visit the home route.

## What's Next?

- Learn more about [Routing](routing) to define custom routes.
- Explore [Middleware](middleware) to handle requests before they reach your controllers.
- Dive into [Views](views) for rendering dynamic content.