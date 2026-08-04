# Query Builder Documentation

This documentation provides an overview of the `DB` class, which serves as a query builder for interacting with a database. The class provides methods for performing various database operations, including transactions, CRUD operations, pagination, and query filtering.

The `DB` builder returns plain PHP arrays. For a fluent, Laravel-like result API, wrap those arrays with `collect(...)`, or use a [Model](models) whose reads return [Collections](models#collections) natively.

---

## Table of Contents
1. [Initialization](#initialization)
2. [Transactions](#transactions)
3. [CRUD Operations](#crud-operations)
4. [Query Filters](#query-filters)
5. [Ordering and Pagination](#ordering-and-pagination)
6. [Raw Queries](#raw-queries)
7. [Aggregation Functions](#aggregation-functions)
8. [Collections](#collections)

---

### Initialization

#### `DB::table(string $table)`
Starts a fluent query on a table and returns a builder instance. This is the entry point for every chained query.

```php
use Eyika\Atom\Framework\Support\Database\DB;

DB::table('users');
```

> Each `DB::table(...)` call returns its **own** builder instance, so two live builders never clobber each other's `WHERE`/`ORDER`/`JOIN`/`LIMIT` state.

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

To serialize read-modify-write access to a row inside a transaction, add a pessimistic write lock with `lockForUpdate()`:

```php
$row = DB::table('wallets')->where('id', 1)->lockForUpdate()->first();
```

---

### CRUD Operations

#### `create(array $values, array|string $select = '*')`
Creates a new record and returns the fetched row.

```php
DB::table('users')->create(['name' => 'John Doe', 'email' => 'john@example.com']);
```

#### `insert(array $values)`
Inserts a record and returns the new insert id.

```php
$id = DB::table('users')->insert(['name' => 'John Doe']);
```

#### `find(int $id, array|string $fields = '*')`
Finds a record by its ID. Returns the row, or `false` on miss.

```php
$user = DB::table('users')->find(1);
```

#### `first(array|string $fields = '*')`
Finds the first record matching the current filters. Returns the row, or `false` on miss.

```php
$user = DB::table('users')->where('status', 'active')->first();
```

#### `findBy(string $key, $value, array|string $select = '*')`
Finds records by a specific column value.

```php
$user = DB::table('users')->findBy('email', 'john@example.com');
```

#### `update(array $values, int|null $id = null)`
Updates records matching the current filters (or a given id).

```php
DB::table('users')->where('id', 1)->update(['name' => 'Jane Doe']);
```

#### `delete(int|null $id = null)`
Deletes records matching the current filters (or a given id).

```php
DB::table('users')->where('id', 1)->delete();
```

---

### Query Filters

#### `where(string $column, string|null $operatorOrValue = null, $value = null)`
Adds a `WHERE` condition to the query.

```php
DB::table('users')->where('name', '=', 'John')->get();
```

#### `orWhere(string $column, string|null $operatorOrValue = null, $value = null)`
Adds an `OR WHERE` condition to the query.

```php
DB::table('users')->where('status', 'active')->orWhere('role', '=', 'admin')->get();
```

#### `whereLike(string $column, $value)`
Adds a `WHERE LIKE` condition.

```php
DB::table('users')->whereLike('name', '%John%')->get();
```

#### `whereNull(string $column)`
Adds a `WHERE column IS NULL` condition.

```php
DB::table('users')->whereNull('deleted_at')->get();
```

Additional filters include `whereIn`, `whereNotIn`, `whereBetween`, `whereNotBetween`, `whereLessThan`, `whereGreaterThan`, `whereEqual`, `whereNotNull`, and their `orWhere...` counterparts, plus joins (`join`, `leftJoin`, `rightJoin`, `fullOuterJoin`) and `distinct`.

---

### Ordering and Pagination

#### `orderBy(string $column = "id", string $direction = "ASC")`
Specifies the order of the results. The column identifier is escaped and the direction is whitelisted to guard against injection.

```php
DB::table('users')->orderBy('name', 'DESC')->get();
```

#### `paginate(int $currentPage = null, int $recordsPerPage = null)`
Retrieves paginated results as a `PaginatedData` object.

```php
$page = DB::table('users')->paginate(1, 10);

$page->collection();  // current page's items as a Collection
$page->toArray();     // data + totalRecords/totalPages/recordsPerPage/currentPage + next/previous URLs
```

---

### Raw Queries

#### `DB::query(string $sql, array $bind = []): array`
Runs a parameterized `SELECT` and returns an array of associative rows. Bindings are named.

```php
DB::query('SELECT * FROM users WHERE id = :id', ['id' => 1]);
```

#### `DB::statement(string $sql)`
Executes a statement (DDL or a write) and returns `true` on success.

```php
DB::statement('DELETE FROM users WHERE id = 1');
```

#### `DB::select(string $sql)`
Runs a raw `SELECT` string and returns all fetched rows.

An instance `->raw($sql, $bind)` is also available off a `DB::table(...)` builder.

---

### Aggregation Functions

#### `count(string $column = "*")`
Counts the number of rows.

```php
$count = DB::table('users')->count();
```

#### `avg(string $column)`
Calculates the average value of a column.

```php
DB::table('users')->avg('age');
```

#### `max(string $column)` / `min(string $column)`
Finds the maximum / minimum value of a column.

```php
DB::table('salaries')->max('amount');
DB::table('salaries')->min('amount');
```

Further aggregates include `sum`, `group_concat`, `var_pop`, `stddev`, `bit_and`, `bit_or`, and `bit_xor`, along with `increment(column, step)` / `decrement(column, step)` for atomic counter updates.

> These are available on **both** builders — `DB::table('users')->count()` and `User::count()` alike, and they respect any `where()` you have already applied.

---

### Grouping and Projection

#### `groupBy(string|array $columns)`

Aggregate per key rather than over the whole table — the database does the work, instead of you fetching every row and summing in PHP:

```php
Order::select(['customer_id'])
     ->selectRaw('SUM(total) AS lifetime')
     ->groupBy('customer_id')
     ->get();
```

#### `having(string $column, mixed $operator = null, mixed $value = null)`

Filter on the aggregate itself — `where()` cannot, because it is applied before grouping:

```php
Order::select(['customer_id'])
     ->selectRaw('SUM(total) AS lifetime')
     ->groupBy('customer_id')
     ->having('SUM(total)', '>', 100)
     ->get();
```

The left-hand side must be a column or a known aggregate over one (`SUM`, `COUNT`, `AVG`, `MIN`, `MAX`, `STDDEV`, `VAR_POP`, `GROUP_CONCAT`, `BIT_AND`, `BIT_OR`, `BIT_XOR`, optionally `DISTINCT`). Anything else **throws** rather than reaching the database. The compared value is always bound.

#### `select(string|array $columns)` and `selectRaw(string $expression)`

Project a subset of columns instead of hydrating every one:

```php
Order::select(['id', 'total'])->where('customer_id', 7)->get();
```

`select()` quotes plain identifiers. `selectRaw()` is separate because it emits your SQL **verbatim** — use it for aggregate expressions, and **never pass user input to it**:

```php
->selectRaw('SUM(total) AS lifetime')
```

The read methods also still accept a projection as an argument — `get(true, ['id', 'total'])` on a model, `get(['id', 'total'])` on `DB::table()` — which is what `DB::table()` uses, since `DB::select()` is already a raw-SELECT executor and cannot double as the chainable form.

> **Clause order does not depend on call order.** `GROUP BY`, `HAVING`, `ORDER BY`, `LIMIT` and `OFFSET` are emitted in correct SQL order however you chain them, so `limit(2)->orderBy('n')` and `orderBy('n')->limit(2)` are equivalent.

---

### Collections

The `DB` builder's reads (`get()`, `all()`, `find()`, `first()`) return plain arrays (or `false` on miss). To use the fluent collection API, wrap the result with the `collect()` helper:

```php
$active = collect(DB::table('users')->get())
    ->where('status', 'active')
    ->pluck('email');
```

Model reads return a [Collection](models#collections) directly, and can stream large result sets lazily with `cursor()` / `lazy()`. See the [Model Query Builder](models) for the collection-native API.
