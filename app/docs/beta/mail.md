# Mail In Atom

## Introduction

Atom ships a small, driver-based mail layer for sending transactional email — verification codes, password resets, notifications, and the like. A single fluent `Mailer` builds the message (recipients, sender, HTML body) and hands it to a configured **transport driver** which actually delivers it.

The default driver is built on **PHPMailer** (SMTP). Additional drivers for hosted providers and for local development/testing are included. Which driver is used is decided by `config('mail.default')`.

---

## Configuration

Mail configuration lives in `config/mail.php`. It defines the default mailer and a `mailers` map, where each entry names a `transport` and its settings.

### Example Configuration File
```php
return [
    // The default mailer, overridable with the MAIL_MAILER env variable.
    'default' => env('MAIL_MAILER', 'smtp'),

    'mailers' => [
        'smtp' => [
            'transport'    => 'smtp',
            'host'         => env('MAIL_HOST', 'smtp.mailgun.org'),
            'port'         => env('MAIL_PORT', 587),
            'encryption'   => env('MAIL_ENCRYPTION', 'tls'),
            'username'     => env('MAIL_USERNAME'),
            'password'     => env('MAIL_PASSWORD'),
            'timeout'      => null,
            'local_domain' => env('MAIL_EHLO_DOMAIN'),
        ],

        'sendmail' => [
            'transport' => 'sendmail',
            'path'      => env('MAIL_SENDMAIL_PATH', '/usr/sbin/sendmail -bs -i'),
        ],

        'log' => [
            'transport' => 'log',
            'channel'   => env('MAIL_LOG_CHANNEL'),
        ],

        'array' => [
            'transport' => 'array',
        ],

        'failover' => [
            'transport' => 'failover',
            'mailers'   => ['smtp', 'log'],
        ],
    ],

    // A global From address used when a message does not set its own.
    'from' => [
        'address' => env('MAIL_FROM_ADDRESS', 'hello@example.com'),
        'name'    => env('MAIL_FROM_NAME', 'Example'),
    ],

    // Paths the HTML/Markdown template renderer searches.
    'markdown' => [
        'theme' => 'default',
        'paths' => [
            resource_path('views/mail'),
        ],
    ],

    'log_channel' => env('MAIL_LOG_CHANNEL', 'email'),
];
```

- **`default`**: the mailer key used when you don't name one explicitly.
- **`mailers`**: each entry's `transport` selects the driver class; the rest of the entry is the driver's config.
- **`markdown.paths`**: where `buildHtml()` looks up your email templates.

> The mailer resolves a driver by reading `config('mail.mailers')[default]['transport']`. The `transport` value — not the mailer key — is what picks the driver, so you may define several mailers that share one transport.

---

## Available Drivers

The `transport` value maps to one of the following driver classes in `Eyika\Atom\Framework\Support\Mail\Drivers`:

| Transport   | Driver              | Notes |
|-------------|---------------------|-------|
| `smtp`      | `SmtpDriver`        | Sends via **PHPMailer** over SMTP. The primary, fully-exercised driver. |
| `sendmail`  | `SendmailDriver`    | Local `sendmail` binary. |
| `ses`       | `SesDriver`         | Amazon SES. |
| `mailgun`   | `MailgunDriver`     | Mailgun HTTP API (requires the `mailgun/mailgun-php` SDK + `key`/`domain` config). |
| `postmark`  | `PostmarkDriver`    | Postmark HTTP API. |
| `log`       | `LogDriver`         | Writes each message (recipients, subject, body) to a log file via Monolog instead of sending. Ideal for local dev. |
| `array`     | `ArrayDriver`       | Keeps messages in memory — useful in tests. |
| `failover`  | `FailoverDriver`    | Wraps an ordered list of other mailers and tries the next one when a send fails. |

> The shipped `config/mail.php` comments mark `smtp` as the supported transport and the hosted-provider drivers (`ses`, `mailgun`, `postmark`, etc.) as not yet fully verified. They are present and implemented, but SMTP is the battle-tested path — verify a provider driver in staging before relying on it.

Every driver implements the same `MailerInterface`:

```php
interface MailerInterface
{
    public function to(string $address, string|null $name = null): MailerInterface;
    public function from(string $address, string $name): MailerInterface;
    public function replyTo(string $address, string|null $name = null): MailerInterface;
    public function send(string $subject, string $body): MailerResponse;
}
```

---

## Generating a Mailable

The `make:mail` command scaffolds a class under `app/Mail`:

```bash
php artisan make:mail VerifyEmail
```

This writes `app/Mail/VerifyEmail.php` with a single `build()` method:

```php
<?php

namespace App\Mail;

class VerifyEmail
{
    /**
     * Build the message — return the rendered body (e.g. a view()).
     */
    public function build(): string
    {
        return '';
    }
}
```

> `make:mail` is a **scaffold generator only** — the framework does not auto-discover or dispatch these classes. The generated class is a convenient place to gather a message's data and produce its HTML body; you decide how to wire it to the `Mailer` (see the pattern below). There is no `Mailable` base class or magic `->send($mailable)` call.

The framework does provide an `app/Mail/Mailer` in a fresh app that simply extends the framework mailer, giving you a project-local entry point:

```php
<?php

namespace App\Mail;

use Eyika\Atom\Framework\Support\Mail\Mailer as BaseMailer;

class Mailer extends BaseMailer
{
}
```

---

## Sending Mail

Messages are composed and sent through the `Mailer`'s fluent, static API. The methods mirror the driver contract, plus a template builder:

```php
use App\Mail\Mailer;

$response = Mailer::to('user@example.com', 'Jane Doe')
    ->from(env('NOREPLY_EMAIL_USER'), env('NOREPLY_EMAIL_NAME'))
    ->replyTo('support@example.com', 'Support')
    ->buildHtml('welcome.html', ['name' => 'Jane'])
    ->send('Welcome aboard');
```

### The Mailer API

- **`Mailer::to(string $address, ?string $name = null)`** — add a recipient.
- **`->from(string $address, ?string $name = null)`** — set the sender.
- **`->replyTo(string $address, ?string $name = null)`** — set a reply-to address.
- **`->buildHtml(string $templateName, array $data = [], ?string $resourcePath = null)`** — render an HTML body from a template. The template is resolved through the Twig-like engine against `config('mail.markdown.paths')` (override with `$resourcePath`), with `$data` exposed to the template.
- **`->send(string $subject, ?string $to = null)`** — deliver the built HTML body. If `$to` is supplied it is added as a recipient before sending. Returns a `MailerResponse`.
- **`Mailer::init(?string $driver = null, ?array $config = null)`** — explicitly select a mailer/driver (or pass an ad-hoc config) instead of the configured default.

> The `Mailer` keeps a **single static driver instance** for the lifetime of the PHP process, so `to()`, `from()`, `replyTo()`, and `buildHtml()` all operate on the same underlying message. After each `send()`, the SMTP driver clears its recipients and reply-tos so a subsequent send within the same process (for example inside a `queue:work` loop) does not accumulate previous recipients.

### The Response

`send()` returns a `MailerResponse`:

```php
class MailerResponse
{
    public bool $success;
    public int|string|null $message_id;
    public string|null $error;
    public \Exception|null $exception;

    public function __toArray(): array; // success, message_id, error, exception
}
```

Inspect it to branch on delivery:

```php
$response = Mailer::to($address)->buildHtml('reset.html', $data)->send('Reset your password');

if (! $response->success) {
    logger(storage_path('logs/email.log'))
        ->error('Mail failed', ['error' => $response->error]);
}
```

---

## A Complete Example

A typical pattern is a small class that gathers the data, renders a template, and sends — the way the starter app's `VerifyEmail` works:

```php
<?php

namespace App\Mail;

use App\Mail\Mailer;
use PHPMailer\PHPMailer\Exception;

class VerifyEmail
{
    private static $mail;

    public function __construct(string $address, string $name)
    {
        self::$mail = Mailer::to($address, $name)
            ->from(env('NOREPLY_EMAIL_USER'), env('NOREPLY_EMAIL_NAME'));
    }

    public static function send(string $address, string $name, string $subject, string $code): bool
    {
        try {
            new static($address, $name);

            static::$mail->buildHtml('verify.html', [
                'title'    => 'Email Verification',
                'header'   => 'Email Verification',
                'contents' => [
                    $name ? "Hello $name," : 'Hi,',
                    'You or someone requested to verify this email account with us.',
                    "Use this code <strong>$code</strong> to verify your email.",
                ],
                'links' => [
                    'Verify Email' => config('app.url') . "/auth/verify?email=$address&code=$code",
                ],
            ]);

            static::$mail->send($subject);
            return true;
        } catch (Exception $e) {
            logger(storage_path('logs/email.log'))
                ->error('Caught a ' . get_class($e) . ': ' . $e->getMessage(), $e->getTrace());
            return false;
        }
    }
}
```

Call it from a controller or job:

```php
VerifyEmail::send($user->email, $user->name, 'Verify your email', $code);
```

---

## Email Templates

`buildHtml()` renders through Atom's lightweight (Twig-like) template engine, searching the directories in `config('mail.markdown.paths')` — by default `resources/views/mail`. Template variables use the same `{{ ... }}` echo syntax described in the [Views](views) documentation.

```html
<!-- resources/views/mail/verify.html -->
<h1>{{ header }}</h1>
{% for line in contents %}
    <p>{{ line }}</p>
{% endfor %}
```

---

## Local Development & Testing

To develop without hitting a real mail provider, point the default mailer at the `log` or `array` transport:

```dotenv
MAIL_MAILER=log
```

- **`log`** writes each rendered message — recipients, subject, and full HTML body — to a log file (default `storage/logs/mail.log`) as a single line, so you can review exactly what would have been sent.
- **`array`** keeps messages in memory only, which is convenient for assertions in tests.

---

## Best Practices

1. **Send from a queue.** Delivery is I/O-bound; dispatch mail from a queued job so requests stay fast. See the queue documentation for `ShouldQueue` jobs.
2. **Use `log` locally.** Set `MAIL_MAILER=log` in development to avoid sending real email while still seeing the output.
3. **Check the response.** `send()` returns a `MailerResponse` — branch on `->success` and log `->error` rather than assuming delivery.
4. **Keep secrets in env.** Provider keys and SMTP credentials belong in `.env`, referenced from `config/mail.php`.
5. **Keep templates in `resources/views/mail`.** That is where `buildHtml()` looks by default.

---

## Conclusion

Atom's mail layer keeps composition and transport cleanly separated: a fluent `Mailer` builds the message and renders its HTML, while an interchangeable driver delivers it. Start on SMTP (or `log` in development), inspect the `MailerResponse`, and swap transports through `config/mail.php` as your delivery needs grow.
