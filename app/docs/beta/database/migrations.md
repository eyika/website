# Migrations and Seeds In Atom

## Introduction

In Atom, migrations and seeds are managed with a **Laravel-style schema builder** and CLI. Migrations handle database schema changes with the fluent `Schema` + `Blueprint` API, while seeds allow you to populate the database with initial or dummy data. All commands are run through `php artisan`.

---

## Migrations

Migrations provide version control for your database schema. They enable you to define schema changes programmatically and track them over time.

### Creating a Migration

To create a new migration, run the following command:
```bash
php artisan make:migration create_users_table
```

This generates a migration file in the `database/migrations` directory. The file name is prefixed with a timestamp (`Y_m_d_His`) to ensure ordering.

### Writing a Migration

A migration returns an anonymous class extending the framework `Migration` base class, with `up()` and `down()` methods. Schema changes are expressed with `Schema` and a `Blueprint` callback:

```php
use Eyika\Atom\Framework\Support\Database\Schema\Migrations\Migration;
use Eyika\Atom\Framework\Support\Database\Schema\Blueprint;
use Eyika\Atom\Framework\Support\Database\Schema\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('email', 255)->unique();
            $table->string('password')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('role_id')->default(1);
            $table->foreign('role_id')->references('id')->on('roles')->onDelete('cascade');
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
```

#### `Blueprint` column types

A migration fails when it **runs**, not when it is written, so a column type that does not exist is
a runtime error in a file that lints clean. This is the full list.

| Method | MySQL | PostgreSQL | SQLite |
|---|---|---|---|
| `id()` | `BIGINT UNSIGNED AUTO_INCREMENT` | `BIGSERIAL` | `INTEGER PRIMARY KEY AUTOINCREMENT` |
| `string($name, $length = 255)` | `VARCHAR` | `VARCHAR` | `TEXT` |
| `char($name, $length)` | `CHAR` | `CHAR` | `TEXT` |
| `text()` / `mediumText()` / `longText()` | `TEXT` family | `TEXT` | `TEXT` |
| `integer()` / `bigInteger()` (+ `unsigned…`) | `INT` / `BIGINT` | `INTEGER` / `BIGINT` | `INTEGER` |
| `tinyInteger()` / `smallInteger()` / `mediumInteger()` (+ `unsigned…`) | `TINYINT` / `SMALLINT` / `MEDIUMINT` | `SMALLINT` / `SMALLINT` / `INTEGER` | `INTEGER` |
| `decimal()` | `DECIMAL` | `NUMERIC` | `NUMERIC` |
| `boolean()` | `TINYINT(1)` | `BOOLEAN` | `INTEGER` |
| `json()` | `JSON` | `JSONB` | `TEXT` |
| `enum()` | `ENUM` | `VARCHAR` + check | `TEXT` |
| `date()` | `DATE` | `DATE` | `DATE` |
| `time()` | `TIME` | `TIME` | `TIME` |
| `dateTime()` | `DATETIME` | `TIMESTAMP` | `DATETIME` |
| `timestamp()` | `TIMESTAMP` | `TIMESTAMP` | `DATETIME` |
| `timestamps()` | adds `created_at` + `updated_at` | | |
| `softDeletes()` | adds a nullable `deleted_at` | | |

> **Reach for `date()` rather than `dateTime()` for a calendar day.** A date-only value stored as a
> datetime holds a midnight nobody meant, and midnight in a timezone east of UTC is the *previous
> day* in UTC. For a pay date or a delivery promise that is a wrong answer, not an imprecise one.

Widths and affinities are per-driver by necessity: Postgres has neither `TINYINT` nor `MEDIUMINT`,
and SQLite has type *affinity* rather than types at all.

#### Modifiers and keys
- **Modifiers**: `->nullable()`, `->default($value)`, `->unique()`.
- **`foreign($col)->references($col)->on($table)->onDelete(...)`**: Foreign-key constraint.
- **`index($cols, $name = null)`**, **`unique($cols, $name = null)`**, **`fulltext()`**,
  **`spatialIndex()`**: composite indexes and constraints.

> **Name a wide composite key.** The generated name is `unique_<col>_<col>_…_<hash>`, which on four
> columns runs past **MySQL's 64-character identifier limit** and fails the migration with
> `1059 Identifier name … is too long`:
>
> ```php
> $table->unique(['cart_id', 'variant_id', 'bundle_id', 'personalisation_key'], 'cart_items_line_unique');
> ```
>
> This one hides: SQLite has no identifier length limit, so a suite running on SQLite stays green,
> and a database that already has the table never re-runs the migration. The first place it can
> surface is a from-scratch MySQL migrate — which is a good reason to run migrations on MySQL in CI
> and not only on SQLite.

#### Schema helpers
- **`Schema::create($table, $callback)`**: Creates a new table.
- **`Schema::table($table, $callback)`**: Alters an existing table.
- **`Schema::dropIfExists($table)`**: Drops a table if present.
- **`Schema::hasTable($table)` / `Schema::columnExists($table, $col)`**: Introspection helpers.

---

### Running Migrations

To apply all pending migrations, use:
```bash
php artisan migrate
```

To run migrations and immediately seed the database:
```bash
php artisan migrate --seed
```

To preview which migrations would run without touching the database (dry run):
```bash
php artisan migrate --pretend
```

### Rolling Back Migrations

To roll back the most recent batch of migrations:
```bash
php artisan migrate:rollback

# Roll back a number of batches
php artisan migrate:rollback --step=2

# Roll back a specific batch
php artisan migrate:rollback --batch=3

# Dry run: list the migrations that would roll back, without touching the database
php artisan migrate:rollback --pretend
```

To roll every migration back:
```bash
php artisan migrate:reset
```

To reset and then re-run all migrations (optionally seeding):
```bash
php artisan migrate:refresh
php artisan migrate:refresh --seed

# Roll back and re-run only the last N batches
php artisan migrate:refresh --step=1
```

To drop all tables and re-run every migration from scratch:
```bash
php artisan migrate:fresh
```

### Checking Migration Status

To see which migrations have been run:
```bash
php artisan migrate:status
```

---

## Seeds

Seeds allow you to populate your database with initial or test data. They are particularly useful for testing and development environments. Seeders live in `database/seeds`.

### Creating a Seeder

To create a new seeder, use the following command:
```bash
php artisan make:seeder UsersTableSeeder
```

This generates a seeder file in the `database/seeds` directory.

### Writing a Seeder

Seeders extend the framework `Seeder` class and implement a `run()` method. Use the inherited `insert($table, $data)` helper (which writes each row via `DB::table($table)->insert(...)`), or call models directly:

```php
namespace Database\Seeds;

use Eyika\Atom\Framework\Support\Database\Seeder\Seeder;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'name'       => 'John Doe',
                'email'      => 'johndoe@example.com',
                'password'   => password_hash('password', PASSWORD_BCRYPT),
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name'       => 'Jane Smith',
                'email'      => 'janesmith@example.com',
                'password'   => password_hash('password', PASSWORD_BCRYPT),
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->insert('users', $data);
    }
}
```

#### Key Methods in Seeders
- **`run()`**: Entry point, invoked by the seeder runner.
- **`insert($table, $data)`**: Inserts each row of `$data` into `$table`.

---

### Running Seeders

To run all seeders in `database/seeds`, use:
```bash
php artisan db:seed
```

To run a specific seeder:
```bash
php artisan db:seed --class=UsersTableSeeder
```

---

## Migration and Seeder Commands

| Command                             | Description                                           |
|-------------------------------------|-------------------------------------------------------|
| `php artisan make:migration`        | Create a new migration file.                          |
| `php artisan migrate`               | Run all pending migrations (`--seed` to also seed, `--pretend` to dry-run). |
| `php artisan migrate:rollback`      | Roll back the last batch (`--step`, `--batch`, `--pretend`). |
| `php artisan migrate:status`        | Check the status of migrations.                       |
| `php artisan migrate:reset`         | Roll back all migrations.                             |
| `php artisan migrate:refresh`       | Reset and re-run all migrations (`--step`, `--seed`). |
| `php artisan migrate:fresh`         | Drop all tables and re-run every migration.           |
| `php artisan make:seeder`           | Create a new seeder file.                             |
| `php artisan db:seed`               | Run all seeders.                                      |
| `php artisan db:seed --class=Class` | Run a specific seeder by class name.                  |

---

## Best Practices

1. **Atomic Migrations**: Ensure each migration handles a single, focused schema change.
2. **Reversible Migrations**: Always implement `down()` so a migration can be rolled back.
3. **Test Before Production**: Test migrations and seeds in a staging environment before running them in production.
4. **Secure Seeds**: Avoid including sensitive data in seeds.
5. **Version Control**: Commit your migrations and seeds to version control to track changes.

---

## Conclusion

Atom's migration and seeding system provides a structured and reliable way to manage database changes and populate data. With a fluent `Schema` builder and familiar Laravel-style `php artisan` commands, Atom keeps schema management expressive, reversible, and easy to reason about.
