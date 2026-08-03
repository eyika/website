## Hashing

Atom ships a password hasher over PHP's native password API. Use it to store passwords; use
[Encryption](advanced/security) for data you need to read back.

> **Hashing is not encryption.** A hash is **one-way** — there is no "unhash". It is also
> independent of `APP_KEY`, so [rotating the application key](advanced/key-rotation) never
> invalidates stored passwords.

### Hashing a password

```php
use Eyika\Atom\Framework\Support\Facade\Hash;

$user->password = Hash::make($request->password);
```

Or the helper, if you prefer:

```php
$user->password = bcrypt($request->password);
```

Each call produces a **different** hash for the same input — the salt is random and stored inside
the hash string. Never compare hashes with `==`; use `check()`.

### Verifying

```php
if (Hash::check($request->password, $user->password)) {
    // credentials are good
}
```

The framework's auth guards already do this for you — `Auth::attempt()` verifies through the same
mechanism, so a password stored with `Hash::make()` works out of the box.

An empty or `null` stored hash always returns `false`, so a user row with no password set can never
be satisfied by an empty submission.

### Upgrading a hash as costs rise

`needsRehash()` reports whether a stored hash was produced with different parameters than are
configured now. Act on it right after a successful check, while you still hold the plaintext:

```php
if (Hash::check($plain, $user->password)) {
    if (Hash::needsRehash($user->password)) {
        $user->update(['password' => Hash::make($plain)]);
    }
    // log the user in
}
```

That transparently strengthens existing passwords over time. Older hashes keep verifying, so there
is never a flag day.

### Configuration

Optional — with no configuration you get bcrypt at PHP's own default cost. Add `config/hashing.php`
to override:

```php
return [
    'driver' => 'bcrypt',          // bcrypt | argon2i | argon2id

    'bcrypt' => [
        'rounds' => 12,
    ],

    'argon' => [
        'memory'  => 65536,
        'time'    => 4,
        'threads' => 1,
    ],
];
```

Anything you leave out falls back to **PHP's** default rather than a value pinned by the framework —
PHP raises those defaults as hardware improves, and inheriting them means you do too.

> Cost is a deliberate trade-off: higher is harder to brute-force but slower on every login. Tune
> `rounds` against your own hardware rather than copying a number.

### Inspecting a hash

```php
Hash::info($user->password);
// ['algo' => '2y', 'algoName' => 'bcrypt', 'options' => ['cost' => 12]]
```

Returns `null` if the string isn't a recognised hash — useful when migrating from a legacy scheme
and you need to tell old rows from new ones.

### Not to be confused with `getHash()`

The global `getHash()` helper is a **keyed HMAC** (`hash_hmac` with `APP_KEY`) used internally for
the lookup replicas that make encrypted columns queryable. It is not a password hash, and unlike
`Hash::make()` it *is* tied to `APP_KEY`. For passwords, always use `Hash`.
