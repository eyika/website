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

- **`app.php`:** General application settings like name, environment, debug mode, etc.
- **`database.php`:** Database connection settings.
- **`cache.php`:** Cache settings.
- **`mail.php`:** Mail configuration.
- **`services.php`:** Third-party service integrations.

### Example: `config/app.php`

Here’s a snippet from the `config/app.php` file:

```php
return [
    'name' => env('APP_NAME', 'YourAppName'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
];
```

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

## Caching Configuration

For better performance in production, you can cache your configuration settings using the following command:

```bash
php artisan config:cache
```

This command compiles all configuration files into a single file, reducing file I/O during requests. To clear the cache, run:

```bash
php artisan config:clear
```

> Note: The last two commands and feature is still under development

## Best Practices

- **Use `.env` for sensitive information:** Avoid hardcoding credentials or sensitive data in your configuration files.
- **Cache configuration in production:** This improves application performance.
- **Organize custom configurations:** Group related settings in their own files for clarity.

## What's Next?

- Learn about [Routing](routing) to define application routes.
- Explore [Middleware](middleware) to manage request lifecycles.