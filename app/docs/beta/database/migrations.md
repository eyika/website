# Migrations and Seeds In Atom

## Introduction

In Atom, migrations and seeds are managed using a system inspired by **Phinx**, with commands styled after **Laravel** for a familiar and developer-friendly experience. Migrations handle database schema changes, while seeds allow you to populate the database with initial or dummy data.

---

## Migrations

Migrations provide version control for your database schema. They enable you to define schema changes programmatically and track them over time.

### Creating a Migration

To create a new migration, run the following command:
```bash
php artisan make:migration CreateUsersTable
```

This will generate a migration file in the `database/migrations` directory. The file name will include a timestamp to ensure order.

### Writing a Migration

Migrations in Atom use the Phinx `AbstractMigration` class. Below is an example of a migration to create a `users` table:

```php
use Phinx\Migration\AbstractMigration;

class CreateUsersTable extends AbstractMigration
{
    public function change()
    {
        $table = $this->table('users');
        $table->addColumn('name', 'string', ['limit' => 255])
              ->addColumn('email', 'string', ['limit' => 255])
              ->addColumn('password', 'string', ['limit' => 255])
              ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
              ->addColumn('updated_at', 'timestamp', ['null' => true, 'default' => null])
              ->addIndex(['email'], ['unique' => true])
              ->create();
    }
}
```

#### Key Methods in Migrations
- **`create()`**: Creates a new table.
- **`addColumn()`**: Adds a column to the table.
- **`addIndex()`**: Adds an index to the table.
- **`change()`**: Used to define reversible migrations (recommended).
- **`up()`** and **`down()`**: Used for defining one-way migrations.

---

### Running Migrations

To apply all pending migrations, use:
```bash
php artisan migrate
```

To migrate up to a specific version:
```bash
php artisan migrate --target=20241223010101
```

### Rolling Back Migrations

To undo the last batch of migrations:
```bash
php artisan migrate:rollback
```

To rollback to a specific version:
```bash
php artisan migrate:rollback --target=20241223010101
```

### Checking Migration Status

To see which migrations have been run:
```bash
php artisan migrate:status
```

---

## Seeds

Seeds allow you to populate your database with initial or test data. They are particularly useful for testing and development environments.

### Creating a Seeder

To create a new seeder, use the following command:
```bash
php artisan make:seeder UsersTableSeeder
```

This will generate a seeder file in the `database/seeds` directory.

### Writing a Seeder

Seeders extend the Phinx `AbstractSeed` class. Below is an example seeder to populate the `users` table:

```php
use Phinx\Seed\AbstractSeed;

class UsersTableSeeder extends AbstractSeed
{
    public function run()
    {
        $data = [
            [
                'name' => 'John Doe',
                'email' => 'johndoe@example.com',
                'password' => password_hash('password', PASSWORD_BCRYPT),
                'created_at' => date('Y-m-d H:i:s'),
            ],
            [
                'name' => 'Jane Smith',
                'email' => 'janesmith@example.com',
                'password' => password_hash('password', PASSWORD_BCRYPT),
                'created_at' => date('Y-m-d H:i:s'),
            ],
        ];

        $this->table('users')->insert($data)->save();
    }
}
```

#### Key Methods in Seeders
- **`table()`**: Targets a specific table.
- **`insert()`**: Inserts data into the table.
- **`save()`**: Saves the data to the database.

---

### Running Seeders

To run all seeders, use:
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
| `php artisan migrate`               | Run all pending migrations.                           |
| `php artisan migrate:rollback`      | Rollback the last batch of migrations.                |
| `php artisan migrate:status`        | Check the status of migrations.                       |
| `php artisan make:seeder`           | Create a new seeder file.                             |
| `php artisan db:seed`               | Run all seeders.                                      |
| `php artisan db:seed --class=Class` | Run a specific seeder by class name.                  |

---

## Best Practices

1. **Atomic Migrations**: Ensure each migration handles a single schema change.
2. **Reversible Migrations**: Use the `change()` method for migrations whenever possible.
3. **Test Before Production**: Test migrations and seeds in a staging environment before running them in production.
4. **Secure Seeds**: Avoid including sensitive data in seeds.
5. **Version Control**: Commit your migrations and seeds to version control to track changes.

---

## Conclusion

Atom’s migration and seeding system, based on Phinx, provides a structured and reliable way to manage database changes and populate data. By leveraging familiar Laravel-style commands, Atom ensures a smooth developer experience while maintaining the power and flexibility of Phinx.