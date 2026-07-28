# Configuration

**Atom** provides a simple yet powerful way to configure your application. All configuration settings are stored in plain PHP files located in the `config` directory from which they utilize the `.env` file.

## The `.env` File

Environment-specific settings are stored in the `.env` file. This file allows you to define environment variables that can be accessed throughout your application.

### Example `.env` File:

```env
APP_NAME=YourAppName
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### Accessing Environment Variables

You can access these variables in your code using the `env()` helper function:

```php
$appName = env('APP_NAME', 'DefaultAppName');
```

The second parameter (`DefaultAppName`) acts as a fallback if the variable is not set.

## Configuration Files

The `config` directory contains files for various aspects of your application, such as:

- **`app.php`:** General application settings like name, environment, debug mode, and the list of service providers.
- **`database.php`:** Database connection settings.
- **`cache.php`:** Cache settings.
- **`mail.php`:** Mail configuration.
- **`services.php`:** Third-party service integrations.

### Example: `config/app.php`

Here’s a snippet from the `config/app.php` file:

```php
return [
    'name' => env('APP_NAME', 'Atom'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'UTC',
    'key' => env('APP_KEY'),
];
```

## Service Providers

Service providers are the bootstrapping layer of your application — they register container bindings and wire up subsystems (routing, events, cache, views, the database, and your own services). **The application owns its provider list.** The framework no longer hardcodes any `App\Providers\*` class; instead, every provider that should load on each request is listed in the `providers` array of `config/app.php`, and the classes themselves live in `app/Providers/`.

```php
// config/app.php
'providers' => [
    /*
     * Default service providers.
     */
    \App\Providers\CacheServiceProvider::class,
    \App\Providers\RouteServiceProvider::class,
    \App\Providers\ConsoleServiceProvider::class,
    \App\Providers\EventServiceProvider::class,
    \App\Providers\ViewServiceProvider::class,
    \App\Providers\DatabaseServiceProvider::class,

    /*
     * Package Service Providers...
     */

    /*
     * Application Service Providers...
     */
    \App\Providers\AppServiceProvider::class,
],
```

Because these are your files, you can freely edit them, remove ones you don't need, or add your own. `RouteServiceProvider` in particular is what maps incoming requests to your route files, so keep it in the list. Generate a new provider with:

```bash
php artisan make:provider PaymentServiceProvider
```

See [Service Container](advanced/service-container) for how providers register and resolve bindings.

## Package Auto-Discovery

Atom packages are discovered automatically — there is no need to add their providers to `config/app.php` by hand. When you `composer require` a package that declares an `extra.atom` block in its own `composer.json`, its service providers (and facade aliases) are registered for you:

```json
{
    "extra": {
        "atom": {
            "providers": ["Vendor\\Pkg\\PkgServiceProvider"],
            "aliases":   { "Pkg": "Vendor\\Pkg\\Facades\\Pkg" }
        }
    }
}
```

Atom caches this discovery into a package manifest so it doesn't re-parse composer metadata on every request. Rebuild the manifest after installing or updating packages:

```bash
php artisan package:discover
```

This normally runs automatically as part of composer's install/update scripts.

### Accessing Configuration Values

You can access configuration values using the `config()` helper function:

```php
$appName = config('app.name');
```

This retrieves the `name` value from the `app.php` configuration file.

## Adding Your Own Configuration Files

You can add custom configuration files to the `config` directory. For example, to add a `social.php` configuration file:

1. Create the file: `config/social.php`.
2. Add settings:

```php
return [
    'facebook' => [
        'client_id' => env('FACEBOOK_CLIENT_ID'),
        'client_secret' => env('FACEBOOK_CLIENT_SECRET'),
        'redirect' => env('FACEBOOK_REDIRECT_URL'),
    ],
];
```

3. Access the settings in your code:

```php
$facebookClientId = config('social.facebook.client_id');
```

## Caching Configuration & Routes

For better performance in production, you can cache your configuration settings into a single compiled file, reducing file I/O during requests:

```bash
php artisan config:cache
```

To clear the compiled configuration, run:

```bash
php artisan config:clear
```

Routes can be cached the same way, so the router doesn't re-parse your route files on every request:

```bash
php artisan route:cache
php artisan route:clear
```

> Note: Route files that define routes with closures are kept dynamic and are not cached — move those to controller actions if you want them in the route cache.

## Best Practices

- **Use `.env` for sensitive information:** Avoid hardcoding credentials or sensitive data in your configuration files.
- **Cache configuration in production:** This improves application performance.
- **Organize custom configurations:** Group related settings in their own files for clarity.

## What's Next?

- Learn about [Routing](routing) to define application routes.
- Explore [Middleware](middleware) to manage request lifecycles.