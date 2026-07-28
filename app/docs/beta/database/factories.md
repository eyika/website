# Model Factories In Atom

Factories generate throwaway rows of data — for seeding a database or setting up fixtures in tests. A factory declares a `definition()` (the default attributes for one record) and the table those records belong to; you then ask it to `make()` the attribute arrays or `create()` them (insert them into the database). States, sequences, after-hooks, and simple relationships let you vary the output without rewriting the definition.

The base class is `Atom\Framework\Database\Factories\Factory` and inserts run through the `Eyika\Atom\Framework\Support\Database\DB` builder.

---

## Generating a Factory

Use the `make:factory` command:

```bash
php artisan make:factory UserFactory
```

The name must be **PascalCase**. The file is written to `database/factories/` in the `Database\Factories` namespace. By default the table is derived from the class name (the `Factory` suffix is stripped, the remainder is snake-cased and pluralised — `UserFactory` → `users`). Override it with `--table`:

```bash
php artisan make:factory CheapCountryFactory --table=cheap_countries
```

### The generated class

```php
<?php

namespace Database\Factories;

use Atom\Framework\Database\Factories\Factory;

class UserFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Define default attributes here
        ];
    }

    protected function getTable(): string
    {
        return 'users';
    }
}
```

The stub also ships with commented usage examples for states, hooks, sequences, and relationships (reproduced below). Fill in `definition()` with the default column values for one record:

```php
public function definition(): array
{
    return [
        'name'       => 'John Doe',
        'email'      => uniqid() . '@example.com',
        'is_active'  => true,
        'created_at' => date('Y-m-d H:i:s'),
    ];
}
```

> There is no bundled fake-data library. `definition()` returns whatever you put in it, so use `uniqid()`, `random_int()`, `bin2hex(random_bytes(...))`, or your own helpers to vary values across records.

---

## Making vs. Creating

A factory has two terminal methods:

### `make(): array`

Builds the attribute arrays **without touching the database**. It returns an array of records (one per `times()` count), each being the associative array your `definition()` produced with any states/sequences applied:

```php
$rows = (new UserFactory())->times(3)->make();
// [ ['name' => ..., ...], ['name' => ..., ...], ['name' => ..., ...] ]
```

### `create(): array`

Runs `make()` and then **inserts** each record into the factory's table via `DB::table($table)->insert($record)`, firing any after-creating hooks and creating related records. It returns the same array of attribute arrays:

```php
$users = (new UserFactory())->times(5)->create();
```

> **What you get back:** `create()` returns the generated attribute arrays — not model instances, and not auto-increment IDs read back from the database. If you need related rows keyed by the parent's `id` (see relationships below), include an explicit `id` in your `definition()` so the value is present in the returned record.

### `times(int $count): static`

Sets how many records `make()` / `create()` produce (default `1`):

```php
(new UserFactory())->times(10)->create();
```

---

## States

`state()` layers modifications on top of `definition()`. It accepts either an array (merged over the defaults) or a callable (receives the data array, returns the modified array):

```php
// Array state
$admin = (new UserFactory())->state(['role' => 'admin'])->create();

// Callable state
$scoped = (new UserFactory())
    ->state(fn ($data) => array_merge($data, ['slug' => strtolower($data['name'])]))
    ->create();
```

States are applied in the order they were added, once per generated record.

---

## Sequences

`sequence()` cycles through a list of overrides, applying the next entry to each successive record. Combined with `times()`, it gives each record distinct values:

```php
$users = (new UserFactory())
    ->sequence([
        ['email' => 'user1@example.com'],
        ['email' => 'user2@example.com'],
        ['email' => 'user3@example.com'],
    ])
    ->times(3)
    ->create();
```

The sequence wraps around if there are more records than entries.

---

## After Hooks

Two hooks run around generation:

- **`afterMaking(callable)`** — runs for each record as its attributes are assembled.
- **`afterCreating(callable)`** — runs for each record after it is inserted.

```php
$user = (new UserFactory())
    ->afterCreating(function ($user) {
        // side effects: send a welcome email, write a log line, etc.
        mail($user['email'], 'Welcome!', 'Thanks for joining.');
    })
    ->create();
```

> **Honest note on `afterMaking`:** the record array is passed to the callback **by value** and its return value is ignored, so an `afterMaking` callback cannot mutate the record — use it for side effects only. To *transform* attributes, use a callable `state()` instead.

---

## Relationships

Two helpers create related records and wire the foreign key to the parent's `id`:

### One-to-one — `for()`

```php
$user = (new UserFactory())
    ->for(new ProfileFactory(), 'user_id')
    ->create();
```

Creates the user, then creates one profile with `user_id` set to the user's `id`.

### One-to-many — `has()`

```php
$user = (new UserFactory())
    ->has(new PostFactory(), 5, 'user_id')
    ->create();
```

Creates the user, then creates five posts, each with `user_id` set to the user's `id`.

Both helpers clone the related factory, apply the foreign-key value as a state, and call `create()` on it. As noted above, this relies on the parent record containing an `id` value (from its `definition()`), since `create()` does not read back a database-assigned key.

---

## Using Factories in Seeders

Call a factory from a seeder (`php artisan make:seeder`, run via `php artisan db:seed`):

```php
<?php

namespace Database\Seeders;

use Database\Factories\UserFactory;

class DatabaseSeeder
{
    public function run(): void
    {
        (new UserFactory())->times(50)->create();

        (new UserFactory())
            ->state(['role' => 'admin'])
            ->create();
    }
}
```

## Using Factories in Tests

For fixtures you often want the data without hitting the database — use `make()`; when a test needs persisted rows, use `create()`:

```php
// In-memory fixture — no DB write
$attributes = (new UserFactory())->make()[0];

// Persisted rows for an integration test
$users = (new UserFactory())->times(3)->create();
```

---

## How Complete Is This?

The factory engine is real but deliberately small. Verified against the source, here is exactly what it does and does not do:

**Supported:** `definition()`, `getTable()`, `times()`, `make()`, `create()` (insert via the `DB` builder), array and callable `state()`, `sequence()`, `afterMaking()` / `afterCreating()`, and `for()` / `has()` relationships.

**Not provided (by design / not yet):**

- No faker/fake-data generator — you supply values yourself in `definition()`.
- No automatic timestamps — add `created_at` / `updated_at` in your definition if the table needs them.
- A factory targets a **table** (`getTable()`), not a `Model` class; `make()`/`create()` return plain attribute arrays, not model instances.
- `create()` does not read auto-increment IDs back from the database; relationship helpers depend on an `id` being present in the parent's definition.
- `afterMaking()` callbacks are side-effect only (the record is passed by value).

For persistence and querying of the rows a factory produces, see the [Model Query Builder](models) and [Migrations](migrations) docs.
