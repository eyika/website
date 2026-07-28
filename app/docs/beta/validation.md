# Validation In Atom

The `Validator` validates incoming data against a set of rules. It is a small, static utility: you hand it the data (a `Request` or a plain array) plus a map of `field => rules`, and it returns the validated subset on success or reports the failures in `Validator::$errors`. Rules are written as pipe-separated strings, the same shorthand used by `$request->validate()`.

---

## Introduction

The class lives at `Eyika\Atom\Framework\Support\Validator`. Every public entry point is **static**, and the collected errors are exposed on the public static property `Validator::$errors`.

```php
use Eyika\Atom\Framework\Support\Validator;
```

Under the hood, `$request->validate()` and `$request->validateOrFail()` (see the [Requests](requests) docs) delegate to this same class, so everything below applies whether you call the validator directly or through the request.

---

## Validating Data

The primary method is:

```php
Validator::validate(Request|array $req_obj, array $params, string $separator = '|', bool $throw = false): bool|array
```

- **$req_obj** — a `Request` instance (its input is read via `->input()`) or a plain associative array.
- **$params** — a map of `field => 'rule|rule|rule'`.
- **$separator** — the character that separates rules in a rule string. Defaults to `|`.
- **$throw** — when `true`, a failed validation throws a `ValidationException` instead of returning `false`.

On **success** it returns an array of the **validated data** (only the fields you listed). On **failure** it returns `false`, and the per-field errors are available on `Validator::$errors`.

### Example: Validating in a Controller

This is the standard pattern used throughout the app's controllers:

```php
<?php

namespace App\Http\Controllers\Api;

use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Validator;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

class CheapCountryController
{
    public function store(Request $request)
    {
        if (!$validated = Validator::validate($request, [
            'name'      => 'required|string',
            'continent' => "required|string|in:$continents",
        ])) {
            return JsonResponse::badRequest('errors in request', Validator::$errors);
        }

        // $validated contains only 'name' and 'continent'
        $country = CheapCountry::getBuilder()->create($validated);

        return JsonResponse::created('cheap country creation successful', $country);
    }
}
```

### Example: Validating a Plain Array

```php
$data = Validator::validate([
    'email' => 'user@example.com',
    'age'   => 21,
], [
    'email' => 'required|email',
    'age'   => 'required|integer|min:18',
]);

if ($data === false) {
    // inspect Validator::$errors
}
```

### Throwing on Failure

Pass `throw: true` to raise a `ValidationException` (which the framework turns into an error response) instead of returning `false`:

```php
$validated = Validator::validate($request, [
    'name' => 'required|string',
], throw: true);
```

The exception carries `Validator::$errors`, the current error message, and the error code (`422` by default). The request helper `$request->validateOrFail(...)` is a thin wrapper around this behaviour.

---

## Where Errors Land

Errors accumulate on the public static array `Validator::$errors`, keyed by field name. Each entry is an array of the messages that field failed:

```php
Validator::validate($request, [
    'email' => 'required|email',
]);

// Validator::$errors example:
// [
//   'email' => ['email is required'],
// ]
```

You can customise the message and HTTP code used when `$throw` is set:

```php
Validator::setErrorMessage('the request could not be processed');
Validator::setErrorCode(400);
```

### Resetting state

Because the class holds its state statically, `Validator::flush()` clears the residue left by the previous call:

```php
Validator::flush();
```

`validate()` re-initialises its working state on every call (it constructs a fresh instance internally), so `flush()` is only needed to wipe a stale `Validator::$errors` before the next `validate()` — the long-running request worker calls it between requests so one user's errors can't leak into another's.

---

## Null / Optional Values

A field whose value is `null` (or absent) passes **every** rule except `required`, `sometimes`, and `forbidden`. In other words, a rule like `integer` or `email` only constrains a value that is actually present. To make a field optional-but-validated-if-present, use `sometimes`:

```php
Validator::validate($request, [
    'name'      => 'sometimes|string',
    'continent' => "sometimes|string|in:$continents",
]);
```

> There is no `nullable` rule — an unrecognised rule name (without a `:` argument) is simply ignored and passes. Rely on `sometimes` and the null-skipping behaviour above rather than inventing rule names.

---

## Built-in Rules

All rules below are verified against `Validator.php`. Rules that take an argument use a colon (`rule:argument`); list arguments are comma-separated.

### Presence

- **required** — the field must be present and non-empty (a non-empty string, a non-empty array, or any non-null scalar).
- **sometimes** — always passes; marks a field as optional so other rules only apply when it is present.
- **forbidden** — the field must **not** be present (must be `null`).
- **confirm** — requires a matching `{field}_confirm` value equal to this field's value (e.g. rule `confirm` on `password` checks that `password_confirm` equals `password`).

### Type checks

- **string** — value is a string.
- **bool** / **boolean** — value is a real boolean.
- **int** / **integer** — value is an int, or a string that passes `FILTER_VALIDATE_INT` (rejects `"1e3"`, `"0x1A"`, `"10.5"`).
- **float** / **double** — value is a float, int, or numeric string.
- **numeric** — value is numeric (`is_numeric`).
- **array** — value is an array.
- **file** — value is an uploaded `File` instance.

### String formats

- **email** — valid email string.
- **url** — valid URL (`FILTER_VALIDATE_URL`).
- **uuid** — valid UUID string.
- **ascii** — ASCII-only string.
- **phone** — valid phone-number string.
- **json** — valid JSON string.
- **base64** — valid base64 string.

### Files

- **image** — an uploaded `File` whose MIME type is one of `image/jpeg`, `image/png`, `image/gif`, `image/webp`.
- **mimes:jpg,png,...** — an uploaded file whose MIME **subtype** (the part after `/`) is one of the listed values.
- **mimetypes:image/jpeg,...** — an uploaded file whose full MIME type is one of the listed values.

### Size / length — `min` and `max`

`min` and `max` adapt to the value's type:

- **string** — number of characters (multi-byte aware).
- **array** — number of items.
- **numeric** — the numeric value itself.
- **file** — the file size in **kilobytes**.

```php
'password' => 'required|string|min:8|max:64',
'tags'     => 'array|max:5',
'age'      => 'integer|min:18',
'avatar'   => 'file|max:2048', // ≤ 2048 KB
```

### Comparison

- **equals:value** — loosely equals the given value.
- **not_equals:value** — does not loosely equal the given value.
- **in:a,b,c** — the (scalar) value is one of the listed options.
- **not_in:a,b,c** — the value is none of the listed options.
- **contains:substr** — a string that contains `substr`.
- **includes:key** — an array that contains `key`.

### Database

- **exist:table,column** — the value exists in `column` of `table`.
- **not_exist:table,column** — the value does **not** exist in `column` of `table`.

```php
'country_id' => 'required|integer|exist:countries,id',
'email'      => 'required|email|not_exist:users,email',
```

> Note the singular spelling: the rules are `exist` / `not_exist`.

---

## Nested Fields (Dot Notation)

Rule keys support dot notation to reach into nested arrays. Both the lookup and the returned validated data preserve the nested structure:

```php
Validator::validate($request, [
    'analytics.totalTrades' => 'required|integer',
    'analytics.winRate'     => 'numeric',
]);
```

---

## Confirming a Value

Add the `confirm` rule to a field and supply a sibling `{field}_confirm` field that must match it:

```php
// Request body: ['password' => 'secret', 'password_confirm' => 'secret']

Validator::validate($request, [
    'password' => 'required|string|min:8|confirm',
]);
```

If `password_confirm` does not equal `password`, the failure is recorded under the `password` key in `Validator::$errors`.

---

## Custom Rules

### The `ValidatorRule` contract

The validator understands custom rule **objects** that extend the abstract base class `Eyika\Atom\Framework\Support\ValidatorRule`:

```php
<?php

namespace Eyika\Atom\Framework\Support;

abstract class ValidatorRule
{
    public string $error;

    abstract public function passes(string $value): bool;

    public function getError(): string
    {
        return $this->error;
    }
}
```

A concrete rule implements `passes()` and sets `$this->error` with the message to report when it fails:

```php
<?php

namespace App\Rules;

use Eyika\Atom\Framework\Support\ValidatorRule;

class StartsWithFx extends ValidatorRule
{
    public function passes(string $value): bool
    {
        if (!str_starts_with($value, 'FX')) {
            $this->error = 'value must start with FX';
            return false;
        }
        return true;
    }
}
```

When the validator encounters a `ValidatorRule` instance it calls `passes()` and, on failure, records `getError()`.

> **Honest caveat:** `Validator::validate()` reads each field's rules by splitting a pipe-separated **string** (`explode($separator, ...)`), so the built-in entry point only consumes string rules today — there is no exposed path to hand a `ValidatorRule` object to `validate()` through the rule map. The object contract exists and the engine honours it internally, but wiring custom rule objects into a `validate()` call requires extending the validator yourself. Contributions welcome.

### `make:rule`

The scaffold command generates a starter rule class:

```bash
php artisan make:rule StartsWithFx
```

This writes `app/Rules/StartsWithFx.php` in the `App\Rules` namespace:

```php
<?php

namespace App\Rules;

class StartsWithFx
{
    /**
     * Determine if the validation rule passes.
     */
    public function passes(string $attribute, mixed $value): bool
    {
        return true;
    }

    /**
     * The validation error message.
     */
    public function message(): string
    {
        return 'The :attribute is invalid.';
    }
}
```

> **Note:** the generated stub is a standalone scaffold — it does **not** extend `ValidatorRule`, and its method shape (`passes(string $attribute, mixed $value)` + `message()`) differs from the `ValidatorRule` contract the validator actually invokes (`passes(string $value)` + `getError()`). Treat `make:rule` as a starting point: to plug a rule into the engine, extend `ValidatorRule` and match its signature as shown above.

---

## Summary

- Call `Validator::validate($data, $rules)`; it returns the validated array on success or `false` on failure.
- Read failures from `Validator::$errors` (per-field arrays of messages).
- Pass `throw: true` (or use `$request->validateOrFail()`) to raise a `ValidationException` instead.
- Rules are pipe-separated strings; use `sometimes` for optional fields and dot notation for nested keys.
- Custom rule objects extend `ValidatorRule`; `make:rule` scaffolds a stub with a different, Laravel-style shape that you adapt.
