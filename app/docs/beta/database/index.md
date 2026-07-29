# Database In Atom

## Introduction

Atom framework provides a robust and intuitive database layer that simplifies interactions with databases. Atom supports raw SQL queries, a fluent query builder, and the Active Record pattern, giving developers the flexibility to choose the best approach for their needs.

Multi-row reads from models return a **Collection** (`Eyika\Atom\Framework\Support\Collections\Collection`) — a Laravel-like collection with 100+ chainable methods (`map`, `filter`, `pluck`, `where`, `first`, `sortBy`, `groupBy`, …). See [Model Query Builder](models) and [Query Builder](query-builder) for details.

---

## Configuration

The database configuration is located in `config/database.php`. This file allows you to define the default database connection and other connection options.

### Example Configuration File
```php
return [
    'default' => 'mysql', // Default connection

    'connections' => [
        'mysql' => [
            'driver'    => 'mysql',
            'host'      => env('DB_HOST', '127.0.0.1'),
            'port'      => env('DB_PORT', '3306'),
            'database'  => env('DB_DATABASE', 'atom'),
            'username'  => env('DB_USERNAME', 'root'),
            'password'  => env('DB_PASSWORD', ''),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
        ],

        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => storage_path('database.sqlite'),
            'prefix'   => '',
        ],
    ],
];
```

- **`default`**: Specifies the default database connection.
- **`connections`**: Defines available database connections.

---

## Query Builder

Atom includes a powerful query builder to construct SQL queries programmatically. The entry point is `DB::table('...')`, which returns a fluent builder instance.

### Selecting Data
```php
use Eyika\Atom\Framework\Support\Database\DB;

// Fetch all users (array of associative rows)
$users = DB::table('users')->get();

// Fetch a single user (associative row, or false on miss)
$user = DB::table('users')->where('id', 1)->first();
```

> The raw `DB` builder returns plain arrays. Wrap a result in `collect($users)` to get a fluent [Collection](query-builder#collections), or use a [Model](models) whose reads already return collections.

### Inserting Data
```php
DB::table('users')->insert([
    'name' => 'John Doe',
    'email' => 'johndoe@example.com',
]);
```

### Updating Data
```php
DB::table('users')
    ->where('id', 1)
    ->update(['email' => 'newemail@example.com']);
```

### Deleting Data
```php
DB::table('users')->where('id', 1)->delete();
```

---

## Raw Queries

For complex queries or when you need full control over SQL, Atom supports raw queries.

### Executing Raw Queries
```php
use Eyika\Atom\Framework\Support\Database\DB;

// Parameterized SELECT — returns an array of associative rows
$results = DB::query('SELECT * FROM users WHERE id = :id', ['id' => 1]);

// Execute a statement (DDL / write) — returns true on success
DB::statement('DELETE FROM users WHERE id = 1');
```

Use named placeholders (`:id`) with bound values to keep queries safe from injection.

---

## Active Record

Atom supports the Active Record pattern through models. A model represents a single table in your database and provides methods for CRUD operations.

### Creating a Model

```php
namespace App\Models;

use Eyika\Atom\Framework\Support\Database\Model;

class User extends Model
{
    public $table = 'users';       // Optional: defaults to the pluralized class name
    public $primaryKey = 'id';

    // fillable / guarded / casts are class CONSTANTS, not properties.
    protected const fillable = ['id', 'name', 'email', 'created_at', 'updated_at'];
    protected const guarded  = ['deleted_at'];

    // Cast attributes to native PHP types on read/write.
    protected const casts = [
        'id'         => 'int',
        'is_active'  => 'boolean',
        'meta'       => 'array',
    ];
}
```

### Using Models

#### Fetching Data
```php
use App\Models\User;

// Fetch all users — returns a Collection (or false when the table is empty)
$users = User::all();

// Fetch a single user by id — returns the model, or null on miss
$user = User::find(1);

// getBuilder() returns a fresh builder instance and is equivalent to a static call
$user = User::getBuilder()->find(1);
```

> Single-record finders (`find()`, `first()`, `findBy()`) return `null` when no row matches. Multi-record reads (`all()`, `get()`) return a `Collection` on success, or `false` when nothing matches.

Because reads return a Collection, you can chain the fluent API directly:

```php
$activeEmails = User::all()
    ->where('is_active', true)
    ->sortBy('name')
    ->pluck('email');
```

#### Inserting Data
```php
$user = new User();
$user->name = 'John Doe';
$user->email = 'johndoe@example.com';
$user->save();
```

#### Updating Data
```php
$user = User::find(1);
$user->email = 'newemail@example.com';
$user->save();
```

#### Deleting Data
```php
$user = User::find(1);
$user->delete();
```

---

## Migrations

Migrations provide a version control system for your database schema, allowing you to manage schema changes programmatically with a Laravel-style schema builder. See the [Migrations and Seeds](migrations) guide for the full reference.

### Creating a Migration

Run the following command to create a new migration file:
```bash
php artisan make:migration create_users_table
```

This creates a timestamped migration file in the `database/migrations` directory.

### Writing a Migration

Example migration to create a `users` table using `Schema` + `Blueprint`:
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
            $table->string('previleges', 256);
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

### Running Migrations
```bash
php artisan migrate
```

### Rolling Back Migrations
```bash
# Roll every migration back
php artisan migrate:reset

# Reset then re-run all migrations
php artisan migrate:refresh
```

---

## Seeding

Database seeders allow you to populate your database with dummy data. Seeders live in `database/seeds` and extend the framework `Seeder` base class.

### Creating a Seeder

Run the following command to create a new seeder:
```bash
php artisan make:seeder UsersTableSeeder
```

### Writing a Seeder

Example seeder for the `users` table:
```php
namespace Database\Seeds;

use Eyika\Atom\Framework\Support\Database\Seeder\Seeder;

class UsersTableSeeder extends Seeder
{
    public function run(): void
    {
        $data = [
            [
                'username'  => env('TEST_USER_NAME'),
                'email'     => env('TEST_USER'),
                'password'  => password_hash(env('TEST_PASS'), PASSWORD_BCRYPT),
                'firstname' => 'Jhony',
                'lastname'  => 'Doe',
                'status'    => 'active',
            ],
        ];

        // Seeder::insert() writes each row via DB::table($table)->insert($row)
        $this->insert('users', $data);
    }
}
```

### Running Seeders
```bash
php artisan db:seed
```

---

## Relationships

Models in Atom support relationships to define associations between tables.

### Example Relationships

#### One-to-Many
```php
class User extends Model
{
    public function posts()
    {
        return $this->hasMany(Post::class);
    }
}
```

#### Many-to-Many
```php
class User extends Model
{
    public function roles()
    {
        return $this->belongsToMany(Role::class);
    }
}
```

#### Using Relationships
```php
// Eager-load the relation with with(), then access it as a property
$user = User::getBuilder()->with('posts')->find(1);
$posts = $user->posts; // Access related posts
```

Eager loading via `with()` batches a single `WHERE IN` query per relation, avoiding N+1 queries.

---

## Best Practices

1. **Use Migrations**: Always use migrations for schema changes.
2. **Use Models for Business Logic**: Encapsulate data-related logic in models.
3. **Secure Queries**: Use parameter binding to prevent SQL injection.
4. **Optimize Queries**: Monitor and optimize complex queries for better performance.
5. **Backup Data**: Always back up your database before running migrations or making significant changes.

---

## Conclusion

Atom framework's database system provides a clean and efficient way to manage your application's data. Whether you're using the query builder, raw queries, or models, the framework ensures a consistent and secure experience for interacting with your database.
