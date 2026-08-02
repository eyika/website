## Rotating the Application Key

This page covers a **one-off, breaking migration**. If your application encrypts data at rest with
`Encrypter` / the `encrypt()` helper, read this **before** upgrading `eyika/atom-framework` — then
upgrade and migrate in the order given under [The migration](#the-migration). Migrating first is a
silent no-op; the reason is explained there.

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

### Two strategies — you may not need a new key

The break is in how the key was *used*, not in the key material itself, so there are two valid
migrations. Pick deliberately:

**A. Keep your existing `APP_KEY` (simpler, recommended for most).** Decrypt old values with the
legacy routine and re-encrypt them with the current `Encrypter` — the key string in `.env` never
changes. Nothing else that derives from `APP_KEY` is disturbed. Entropy improves from ~150 bits to
whatever the decoded key holds (~192 bits for a key minted by the old `key:generate`, which used
`Str::random(32)`).

**B. Rotate to a brand-new key (maximum strength).** Move the current value to `APP_KEY_OLD`, run
`key:generate` for a fresh 256-bit key, then decrypt-with-old / re-encrypt-with-new. You get the
full 256 bits — but read the warning below first.

#### If you rotate, hash replicas break too

Models using `const encrypted` also maintain a companion `<column>_hash` replica so encrypted
columns remain queryable by equality:

```php
$values[$key . static::hashed_col_suffix] = getHash($values[$key], 'sha256', env('APP_KEY'));
```

`getHash()` is `hash_hmac('sha256', $data, APP_KEY)`. Change `APP_KEY` and every stored `_hash`
becomes unmatchable — a lookup recomputes the hash with the new key and finds nothing. **This fails
silently**: no exception, just rows that no longer resolve. It is easily the nastiest part of a
rotation.

So under **strategy B** you must also recompute every `_hash` column in the same pass — decrypt the
value, re-encrypt it, *and* rewrite its hash replica. Under **strategy A** the replicas are
untouched and there is nothing to do.

Check whether this applies to you:

```bash
grep -rn "const encrypted" app/ database/     # non-empty list => you have _hash replicas
```

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

#### Order matters: upgrade the framework FIRST

It is tempting to migrate the data *before* upgrading, to avoid a window of unreadable
credentials. **That does not work, and it fails silently.**

`Encrypter::encrypt()` resolves to whatever is installed in `vendor/`. Run the migration before
`composer update` and you decrypt with the legacy routine and re-encrypt with the *same legacy
implementation* — a no-op that leaves the weak key in place and reports success. The upgrade then
breaks every value anyway.

So the sequence is:

1. **Take a database backup** and enter a maintenance window.
2. **`composer update eyika/atom-framework`.** From this moment your encrypted columns are
   unreadable — this is the window the maintenance mode exists for.
3. **Write `APP_KEY_OLD` into `.env`** (see the two strategies below for what its value is).
4. **Run the migration command.** Its `decryptLegacy()` is deliberately self-contained — it does not
   call the framework — which is precisely what lets it read old values *after* the framework has
   moved on. Re-encryption goes through the new `Encrypter`.
5. **Verify**, leave maintenance mode, then remove `APP_KEY_OLD`.

**Strategy A — keep the existing key:**

At step 3, copy the current `APP_KEY` value verbatim into `APP_KEY_OLD` and leave `APP_KEY`
unchanged. The command then has an explicit handle on the legacy key rather than relying on the two
being equal.

**Strategy B — rotate to a new key:**

At step 3, move the current value into `APP_KEY_OLD`, then run `php artisan key:generate` to mint
the new key. You must **also** recompute every `<column>_hash` replica in the same pass (see the
warning above) — otherwise equality lookups on encrypted columns start silently failing.

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
