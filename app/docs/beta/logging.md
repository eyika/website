# Logging In Atom

## Introduction

The Atom framework provides a powerful and flexible logging system to help developers capture and manage application logs. Logs can record information about application events, errors, or any custom message you want to track. These logs are crucial for debugging, performance monitoring, and auditing.

Atom logs through **Monolog** under the hood. The `logger()` helper returns a configured Monolog logger, so you get Monolog's full set of levels, handlers, and formatters.

---

## Configuration

The logging configuration lives in `config/logging.php`. It defines the default channel and the set of channels your application can write to.

### Example Configuration File
```php
use Monolog\Handler\StreamHandler;

return [
    // Default channel, overridable with the LOG_CHANNEL env variable.
    'default' => env('LOG_CHANNEL', 'single'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['single', 'bugsnag'],
        ],
        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/atom.log'),
            'level' => 'debug',
        ],
        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/atom.log'),
            'level' => 'debug',
            'days' => 14,
        ],
        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Atom Log',
            'emoji' => ':boom:',
            'level' => 'critical',
        ],
    ],
];
```

- **`default`**: The default log channel to use (via `LOG_CHANNEL`).
- **`channels`**: Defines the available logging channels and their drivers.

Available drivers include `single`, `daily`, `slack`, `syslog`, `errorlog`, `monolog`, `custom`, and `stack`.

---

## Writing Logs

### Using the Logger Helper
The primary way to write logs is the `logger()` helper, which returns a Monolog logger you can call any level method on.

#### Example
```php
logger()->info('User logged in', ['user_id' => 1]);
logger()->error('An unexpected error occurred', ['error' => $exception->getMessage()]);
```

By default `logger()` writes to `storage/logs/custom.log`. Pass a path as the first argument to write elsewhere (for example, a per-feature log file):

```php
logger(storage_path('logs/email.log'))->info('Sending verification email');
```

### The `info()` Shortcut
For quick informational logs there is a convenience helper:

```php
info('Cache warmed', ['keys' => 42]);
```

### Supported Log Levels
The logger supports the standard PSR-3 / Monolog levels:
- emergency
- alert
- critical
- error
- warning
- notice
- info
- debug

```php
logger()->debug('Debugging information');
logger()->warning('This is a warning');
logger()->critical('Critical system failure');
```

---

## Contextual Data

You can attach additional context to a log entry by passing an array as the second argument. This context can include any information that helps you understand the event.

#### Example
```php
logger()->info('User registered', ['user_id' => 42, 'email' => 'example@example.com']);
```

In the log file, this appears as:
```text
[2024-12-23 12:00:00] Atom.INFO: User registered {"user_id":42,"email":"example@example.com"}
```

---

## Choosing a Destination

Rather than a facade `channel()` call, `logger()` accepts arguments that control where and how the log is written. Its signature is:

```php
logger(
    ?string $path = null,      // target log file (defaults to storage/logs/custom.log)
    Level $level = Level::Debug,
    bool $bubble = true,
    ?int $filePermission = null,
    bool $useLocking = false,
    bool $internal = false,    // when true, silenced unless app.debug is on
    ?string $name = null,      // channel name shown in the log line
    bool $isConsole = false    // write colorised output to stdout instead of a file
);
```

### Writing to a specific file (channel)
```php
logger(storage_path('logs/webhook.log'))->info('Webhook received');
```

### Naming the channel
```php
use Monolog\Level;

logger(storage_path('logs/atom.log'), Level::Warning, name: 'payments')
    ->warning('Charge retried');
```

### Console logging
Pass `isConsole: true` to emit colorised output to `php://stdout` — useful inside console commands and jobs:

```php
logger(isConsole: true)->info('Job started');
```

---

## Advanced Features

### Logging Exceptions
Because context is just an array, you can log an exception's message and trace:

```php
try {
    // Some code that might throw an exception
} catch (\Exception $e) {
    logger()->error(
        'Caught a ' . get_class($e) . ': ' . $e->getMessage(),
        $e->getTrace()
    );
}
```

### Extending with Monolog
`logger()` returns a `Monolog\Logger`, so you can push additional handlers or formatters onto it, or build your own logger for a bespoke destination:

```php
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Logger;

$logger = new Logger('slack');
$logger->pushHandler(new SlackWebhookHandler(env('LOG_SLACK_WEBHOOK_URL'), Logger::ERROR));

$logger->error('Critical error reported');
```

---

## Viewing Logs

Logs are stored in the `storage/logs` directory. View them with a text editor or a command-line tool like `tail`:

```bash
tail -f storage/logs/atom.log
```

---

## Best Practices

1. **Use Appropriate Log Levels**: Use the correct log level for the severity of the event.
2. **Avoid Sensitive Data**: Do not log sensitive user data, such as passwords or API keys.
3. **Log Contextual Information**: Always include context to make logs more useful for debugging.
4. **Separate Concerns**: Write feature-specific logs to their own files (e.g. `logs/email.log`) to keep them easy to scan.
5. **Monitor Logs**: Regularly monitor logs for critical issues or anomalies.

---

## Conclusion

Logging in Atom is designed to be intuitive and flexible, enabling developers to effectively track and diagnose application behavior. By leveraging Monolog through the `logger()` helper, you can maintain a clear and comprehensive log history for debugging, auditing, and system monitoring purposes.
