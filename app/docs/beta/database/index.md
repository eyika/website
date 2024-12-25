# Database In Atom

## Introduction

Atom framework provides a robust and intuitive database layer that simplifies interactions with databases. Atom supports raw SQL queries, query builders, and the Active Record pattern, giving developers the flexibility to choose the best approach for their needs.

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

Atom includes a powerful query builder to construct SQL queries programmatically.

### Selecting Data
```php
use Eyika\Atom\Framework\Support\Database\DB;

// Fetch all users
$users = DB::init()->get('users');

// Fetch a single user
$user = DB::init()->where('id', 1)->first('users');
```

### Inserting Data
```php
DB::init()->insert('users', [
    'name' => 'John Doe',
    'email' => 'johndoe@example.com',
]);
```

### Updating Data
```php
DB::init()
    ->where('id', 1)
    ->update('users', ['email' => 'newemail@example.com']);
```

### Deleting Data
```php
DB::init()->where('id', 1)->delete('users');
```

---

## Raw Queries

For complex queries or when you need full control over SQL, Atom supports raw queries.

### Executing Raw Queries
```php
use Eyika\Atom\Framework\Support\Database\DB;

// Running a raw select query
$results = DB::raw('SELECT * FROM users WHERE id = ?', [1]);

// Running a raw insert/update/delete query
DB::raw('DELETE FROM users WHERE id = ?', [1]);
```

---

## Active Record

Atom supports the Active Record pattern through models. A model represents a single table in your database and provides methods for CRUD operations.

### Creating a Model

```php
namespace App\Models;

use Eyika\Atom\Framework\Support\Database\Model;

class User extends Model
{
    protected $table = 'users'; // Optional: default is the pluralized class name
    protected $primaryKey = 'id';
    protected $fillable = ['name', 'email'];
}
```

### Using Models

#### Fetching Data
```php
use App\Models\User;

// Fetch all users
$users = User::getBuilder()->all();

// Fetch a single user
$user = User::getBuilder()->find(1);
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
$user = User::getBuilder()->find(1);
$user->email = 'newemail@example.com';
$user->save();
```

#### Deleting Data
```php
$user = User::getBuilder()->find(1);
$user->delete();
```

---

## Migrations

Migrations provide a version control system for your database schema, allowing you to manage schema changes programmatically.

### Creating a Migration

Run the following command to create a new migration file:
```bash
php atom make:migration CreateUsersTable
```

> Note: You must follow the naming convention above

This will create a new migration file in the `database/migrations` directory.

### Writing a Migration

Example migration to create a `users` table:
```php
use Phinx\Migration\AbstractMigration;

class CreateUsersTable extends Migration
{
    const TABLE_NAME = 'users';

    public function change()
    {
        $table = $this->table($this::TABLE_NAME);
        $table->addColumn('name', 'string', ['limit' => 30])
            ->addColumn('previleges', 'string', ['limit' => 256])
            ->addColumn('deleted_at', 'timestamp', ['null' => true, 'default' => null])
            ->addTimestamps()
            ->addIndex(['previleges'], ['unique' => true])
            ->create();
    }
}
```

### Running Migrations
```bash
php artisan migrate
```

### Rolling Back Migrations
```bash
php artisan migrate:rollback
```

---

## Seeding

Database seeders allow you to populate your database with dummy data.

### Creating a Seeder

Run the following command to create a new seeder:
```bash
php artisan make:seeder UsersTableSeeder
```

### Writing a Seeder

Example seeder for the `users` table:
```php
use Dotenv\Dotenv;
use Phinx\Seed\AbstractSeed;

class UserSeeder extends AbstractSeed
{
    /**
     * Run Method.
     *
     * Write your database seeder using this method.
     *
     * More information on writing seeders is available here:
     * https://book.cakephp.org/phinx/0/en/seeding.html
     */
    public function run(): void
    {
        // $dotenv = strtolower(PHP_OS_FAMILY) === 'windows' ? Dotenv::createImmutable(__DIR__."\\..\\..\\") : Dotenv::createImmutable(__DIR__.'/../../');
        // $dotenv->safeLoad();

        // $dotenv->required(['TEST_USER_NAME', 'TEST_USER', 'TEST_PASS'])->notEmpty();
        $ids = $this->fetchRow("SELECT id FROM roles WHERE name = 'admin'");
        $id = !$ids ? 1 : $ids[0];
        $data = [
            [
                'username' => env('TEST_USER_NAME'),
                'email' => env('TEST_USER'),
                'password' => password_hash(env('TEST_PASS'), PASSWORD_BCRYPT),
                'phone' => '08100000000',
                'firstname' => 'Jhony',
                'lastname' => 'Doe',
                'status' => 'active',
                'role_id' => $id
            ]
        ];

        $users = $this->table('users');
        $users->insert($data)->saveData();
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
$user = User::getBuilder()->find(1);
$posts = $user->posts; // Access related posts
```

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