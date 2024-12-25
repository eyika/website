# Logging In Atom

## Introduction

The Atom framework provides a powerful and flexible logging system to help developers capture and manage application logs. Logs can record information about application events, errors, or any custom message you want to track. These logs are crucial for debugging, performance monitoring, and auditing.

The logging system in Atom supports multiple log levels, custom log channels, and different logging destinations (e.g., files, console, or external services).

---

## Configuration

The logging configuration is typically defined in the `config/logging.php` file. This configuration file determines the log channels and their behavior.

### Example Configuration File
```php
return [
    'default' => 'single', // Default log channel

    'channels' => [
        'single' => [
            'driver' => 'file',
            'path' => storage_path('logs/atom.log'),
            'level' => 'debug',
        ],

        'daily' => [
            'driver' => 'file',
            'path' => storage_path('logs/atom.log'),
            'level' => 'debug',
            'days' => 14,
        ],

        'console' => [
            'driver' => 'console',
            'level' => 'info',
        ],
    ],
];
```

- **`default`**: The default log channel to use.
- **`channels`**: Defines the available logging channels.

---

## Writing Logs

### Using the Logger Helper
You can write logs using the `logger()` helper function, which writes to the default channel.

#### Example
```php
logger()->info('User logged in', ['user_id' => 1]);
logger()->error('An unexpected error occurred', ['error' => $exception->getMessage()]);
```

### Using the `Log` Facade
You can also use the `Log` facade to access the logger.

#### Example
```php
use Eyika\Atom\Framework\Support\Facades\Log;

Log::debug('Debugging information');
Log::warning('This is a warning');
Log::critical('Critical system failure');
```

### Supported Log Levels
The Atom framework supports the following log levels:
- emergency
- alert
- critical
- error
- warning
- notice
- info
- debug

---

## Contextual Data

You can provide additional context for log entries using an array. This context can include any information that may help understand the event.

#### Example
```php
Log::info('User registered', ['user_id' => 42, 'email' => 'example@example.com']);
```

In the log file, this will appear as:
```text
[2024-12-23 12:00:00] local.INFO: User registered {"user_id":42,"email":"example@example.com"}
```

---

## Log Channels

A log channel defines where and how the logs are recorded. Atom supports several channel drivers:

### 1. Single File Logging
Logs all messages to a single file.

```php
'single' => [
    'driver' => 'file',
    'path' => storage_path('logs/atom.log'),
    'level' => 'debug',
],
```

### 2. Daily File Logging
Logs messages to separate files for each day, retaining logs for a specified number of days.

```php
'daily' => [
    'driver' => 'file',
    'path' => storage_path('logs/atom.log'),
    'level' => 'debug',
    'days' => 14,
],
```

### 3. Console Logging
Outputs log messages to the console.

```php
'console' => [
    'driver' => 'console',
    'level' => 'info',
],
```

### 4. Custom Channels
You can define custom log channels by implementing your own log handlers. More details will be provided in the advance section of this docs

---

## Switching Channels

To log messages to a specific channel, use the `channel()` method.

#### Example
```php
Log::channel('daily')->info('Daily log entry');
Log::channel('console')->warning('Console log entry');
```

---

## Advanced Features

### Logging Exceptions
Atom allows you to log exceptions easily.

#### Example
```php
try {
    // Some code that might throw an exception
} catch (Exception $e) {
    Log::error('Exception caught', ['exception' => $e]);
}
```

### Logging with Monolog
Atom leverages Monolog under the hood, so you can extend or customize logging behavior using Monolog's features.

#### Example
```php
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Logger;

$logger = new Logger('slack');
$logger->pushHandler(new SlackWebhookHandler('your-slack-webhook-url', Logger::ERROR));

Log::channel('slack')->error('Critical error reported');
```

---

## Viewing Logs

Logs are typically stored in the `storage/logs` directory. You can view them using a text editor or a command-line tool like `tail`:

```bash
tail -f storage/logs/atom.log
```

---

## Best Practices

1. **Use Appropriate Log Levels**: Use the correct log level for the severity of the event.
2. **Avoid Sensitive Data**: Do not log sensitive user data, such as passwords or API keys.
3. **Log Contextual Information**: Always include context to make logs more useful for debugging.
4. **Rotate Logs**: Use daily logging to prevent log files from growing too large.
5. **Monitor Logs**: Regularly monitor logs for critical issues or anomalies.

---

## Conclusion

Logging in Atom is designed to be intuitive and flexible, enabling developers to effectively track and diagnose application behavior. By leveraging the robust features of the logging system, you can maintain a clear and comprehensive log history for debugging, auditing, and system monitoring purposes.