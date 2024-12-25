# Query Builder Documentation

This documentation provides an overview of the `DB` class, which serves as a query builder for interacting with a database. The class provides methods for performing various database operations, including transactions, CRUD operations, pagination, and query filtering.

---

## Table of Contents
1. [Initialization](#initialization)
2. [Transactions](#transactions)
3. [CRUD Operations](#crud-operations)
4. [Query Filters](#query-filters)
5. [Ordering and Pagination](#ordering-and-pagination)
6. [Raw Queries](#raw-queries)
7. [Aggregation Functions](#aggregation-functions)

---

### Initialization

#### `DB::init()`
Initializes the `DB` class instance.

```php
DB::init();
```

---

### Transactions

#### `DB::beginTransaction()`
Starts a database transaction.

```php
DB::beginTransaction();
```

#### `DB::commit()`
Commits the current transaction.

```php
DB::commit();
```

#### `DB::rollback()`
Rolls back the current transaction.

```php
DB::rollback();
```

---

### CRUD Operations

#### `DB::create(string $table, array $values, array|string $select = '*')`
Creates a new record in the specified table.

```php
DB::create('users', ['name' => 'John Doe', 'email' => 'john@example.com']);
```

#### `DB::find(string $table, int $id, array|string $fields = '*')`
Finds a record by its ID.

```php
$user = DB::find('users', 1);
```

#### `DB::first(string $table, int $id = 1, array|string $fields = '*')`
Finds the first record matching the criteria.

```php
$user = DB::first('users');
```

#### `DB::findBy(string $table, string $key, $value, array|string $select = '*')`
Finds a record by a specific column value.

```php
$user = DB::findBy('users', 'email', 'john@example.com');
```

#### `DB::update(string $table, array $values, int $id)`
Updates a record by its ID.

```php
DB::update('users', ['name' => 'Jane Doe'], 1);
```

#### `DB::delete(string $table, int $id)`
Deletes a record by its ID.

```php
DB::delete('users', 1);
```

---

### Query Filters

#### `DB::where(string $column, string|null $operatorOrValue = null, $value = null)`
Adds a `WHERE` condition to the query.

```php
DB::where('name', '=', 'John')->get('users');
```

#### `DB::orWhere(string $column, string|null $operatorOrValue = null, $value = null)`
Adds an `OR WHERE` condition to the query.

```php
DB::orWhere('name', '=', 'John')->get('users');
```

#### `DB::whereLike(string $column, $value)`
Adds a `WHERE LIKE` condition.

```php
DB::whereLike('name', '%John%')->get('users');
```

#### `DB::whereNull(string $column)`
Adds a `WHERE column IS NULL` condition.

```php
DB::whereNull('deleted_at')->get('users');
```

---

### Ordering and Pagination

#### `DB::orderBy(string $column = "id", string $direction = "ASC")`
Specifies the order of the results.

```php
DB::orderBy('name', 'DESC')->get('users');
```

#### `DB::paginate(string $table, int $currentPage = 1, int $recordsPerPage = 10)`
Retrieves paginated results.

```php
DB::paginate('users', 1, 10);
```

---

### Raw Queries

#### `DB::raw(string $sql, array $bind)`
Executes a raw SQL query.

```php
DB::raw('SELECT * FROM users WHERE id = ?', [1]);
```

---

### Aggregation Functions

#### `DB::count(string $table, string $column = "*")`
Counts the number of rows in a table.

```php
$count = DB::count('users');
```

#### `DB::avg(string $column)`
Calculates the average value of a column (Not implemented).

```php
DB::avg('age');
```

#### `DB::max(string $column)`
Finds the maximum value of a column (Not implemented).

```php
DB::max('salary');
```

#### `DB::min(string $column)`
Finds the minimum value of a column (Not implemented).

```php
DB::min('salary');
```

---

This documentation highlights the core functionalities of the `DB` class. Methods marked as "Not implemented" indicate planned features that are yet to be developed.