## Rotating the Application Key

This page covers a **one-off, breaking migration**. If your application encrypts data at rest with
`Encrypter` / the `encrypt()` helper, read this **before** upgrading `eyika/atom-framework`.

### What changed, and why

`key:generate` writes your key in the form `APP_KEY=base64:…`. Until this release the `Encrypter`
took that string **verbatim** and handed it to `openssl_encrypt()`, which silently truncates the key
to the cipher's length. For `AES-256-CBC` that meant the effective 32-byte key was:

```
base64:aGtDWURGaW5CQlJPdGRBSVRkW
└─────┘
 7 bytes of every key were the literal constant "base64:"
```

Seven key bytes were publicly known, and the remaining twenty-five were base64 characters — roughly
150 bits of entropy instead of the intended 256. `key:generate` compounded it by building the key
from `Str::random(32)` (32 printable base64-alphabet characters, ~192 bits) rather than 32 raw
random bytes.

The `Encrypter` now decodes the `base64:` prefix to raw bytes and asserts the key is exactly the
length its cipher requires, failing closed otherwise. `key:generate` now uses `random_bytes(32)`.

**There is deliberately no fallback to the old key.** A fallback would keep the weak key alive
indefinitely and defeat the fix, so payloads written under the old behaviour are rejected with
`The MAC is invalid.` That is what makes this a migration rather than a transparent upgrade.

### What is affected

Only data encrypted with `Encrypter` / `encrypt()`. Specifically:

| Concern | Affected? | Why |
|---|---|---|
| **User passwords** | **No** | Passwords are *hashed*, not encrypted. The auth drivers use PHP's `password_verify()`, which never involves `APP_KEY`. **Your users can still log in.** |
| **JWT tokens** | **No** | `JwtGuard` signs with `config('app.key')` passed straight to `JWT::encode()` — it never goes through the `Encrypter`, so nothing about it changes. |
| **Remember-me cookies** | Yes, harmlessly | `SessionGuard::recall()` already catches a failed decrypt and returns `null`, so a stale cookie just means the user sees the login page. No error, no action needed. |
| **Encrypted columns** | **Yes — migrate these** | Anything written through `Encrypter::encrypt()`, whether via a model's `const encrypted` list or explicit calls in your own setters. |

### Finding your encrypted data

Check **both** mechanisms — an app can use either or both:

```bash
# 1. the framework's per-model list
grep -rn "const encrypted" app/ database/

# 2. explicit calls (easy to miss — these are not in any `encrypted` list)
grep -rn "Encrypter::\|encrypt(\|decrypt(" app/
```

If neither turns up anything that touches persisted columns, you have nothing to migrate: upgrade
normally and your users will simply re-login where remember-me was in play.

### The migration

The shape is always the same:

1. Keep the current key as `APP_KEY_OLD` in `.env`.
2. Run `php artisan key:generate` to mint a new, correct key.
3. For every encrypted column: read the stored value, decrypt it with the **legacy** routine below
   using `APP_KEY_OLD`, then re-encrypt with the framework's current `Encrypter` and write it back.
4. Verify, then remove `APP_KEY_OLD`.

Do this behind a maintenance window, **take a database backup first**, and always run a dry pass
before writing.

> `APP_KEY_OLD` must live in `.env`. The `env()` helper reads `$_ENV`, and stock `php.ini` ships
> `variables_order="GPCS"` (no `E`), so a shell-exported variable will **not** reach it.

#### The legacy decrypt routine

The old flow used the configured key string **verbatim** as both the cipher key and the HMAC key.
Reproduce it exactly — do not decode `base64:` here, that is the whole point:

```php
/**
 * Decrypt a payload written before the framework decoded `base64:` keys.
 * $legacyKey is the raw APP_KEY_OLD string, prefix and all.
 */
function decryptLegacy(string $payload, string $legacyKey, bool $unserialize = false): mixed
{
    $data = json_decode(base64_decode($payload), true);

    if (!is_array($data) || !isset($data['iv'], $data['value'], $data['mac'])) {
        throw new RuntimeException('The payload is invalid.');
    }

    // The old code keyed the HMAC with the same verbatim string.
    $calculated = hash_hmac('sha256', $data['iv'] . $data['value'], $legacyKey);

    if (!hash_equals($data['mac'], $calculated)) {
        throw new RuntimeException('The MAC is invalid — wrong legacy key?');
    }

    $plain = openssl_decrypt(
        $data['value'], 'AES-256-CBC', $legacyKey, 0, base64_decode($data['iv'])
    );

    if ($plain === false) {
        throw new RuntimeException('Could not decrypt the data.');
    }

    return $unserialize ? unserialize($plain) : $plain;
}
```

Keep this inside the migration command only. It is the weak flow by definition, so it should not
outlive the migration — delete the command once every environment has been migrated.

#### Re-encrypting

```php
use Eyika\Atom\Framework\Support\Facade\Encrypter;

$legacyKey = env('APP_KEY_OLD');

foreach (BrokerConnection::all() as $row) {
    foreach (['access_token', 'refresh_token', 'api_secret', 'passphrase'] as $column) {
        if (empty($row->{$column})) {
            continue;
        }

        $plain = decryptLegacy($row->{$column}, $legacyKey);

        if ($dryRun) {
            $this->info("{$row->id}.{$column}: would re-encrypt (" . strlen($plain) . " bytes)");
            continue;
        }

        $row->{$column} = Encrypter::encrypt($plain);
    }

    $dryRun or $row->save();
}
```

Make the dry run the **default** and require an explicit flag to write. Report per-row and
per-column counts, and never print decrypted values — log lengths, not secrets.

### Rolling back

If a migration goes wrong, the old ciphertext is still valid under `APP_KEY_OLD`: restore the
database backup and put the old value back in `APP_KEY`. This is why `APP_KEY_OLD` is only removed
once the migration is verified.

### Verifying

- Read one migrated row through its normal accessor and confirm the plaintext is right.
- Confirm nothing still fails with `The MAC is invalid.` in your logs.
- Confirm `APP_KEY` is `base64:` prefixed and decodes to exactly 32 bytes:

```bash
php -r "echo strlen(base64_decode(substr(getenv('APP_KEY') ?: '', 7))), PHP_EOL;"   # expect 32
```

A wrong-length key now fails loudly at construction — the `Encrypter` refuses to run rather than
silently encrypting with a truncated key, which was the original bug.
