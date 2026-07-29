# Model Query Builder In Atom

The Model Query Builder provides a fluent interface for interacting with your database models, enabling you to perform CRUD operations and build complex queries. Multi-row reads return a **Collection**, and large result sets can be streamed lazily via the database cursor.

> **`getBuilder()` is optional.** Every query method can be called **directly on the model** — `User::where('active', true)->first()`, `User::find(1)`, `User::create([...])`. Static calls are transparently proxied to a fresh builder, so `User::where(...)` and `User::getBuilder()->where(...)` are equivalent. Prefer the shorter direct form; reach for `getBuilder()` only when you want the builder instance up front (e.g. to start a chain with an instance-only method like `orderBy()` or `with()`).

---

## Table of Contents

1. [Initialization](#initialization)
2. [CRUD Operations](#crud-operations)
3. [Query Building](#query-building)
4. [Collections](#collections)
5. [Streaming Large Results](#streaming-large-results)
6. [Aggregates](#aggregates)
7. [Pagination](#pagination)
8. [Model Events & Observers](#model-events--observers)
9. [Dynamic Methods](#dynamic-methods)

---

## Initialization

### Creating a Model Instance

```php
$user = new User(['name' => 'John Doe']);
```

- **$values** *(array)*: Initial attributes for the model.

A model declares its writable columns and casts as class **constants** (`fillable`, `guarded`, `casts`), while `table` and `primaryKey` are properties:

```php
use Eyika\Atom\Framework\Support\Database\Model;

class User extends Model
{
    public $table = 'users';
    public $primaryKey = 'id';

    protected const fillable = ['id', 'name', 'email', 'created_at', 'updated_at'];
    protected const guarded  = ['deleted_at'];
    protected const casts    = ['id' => 'int', 'is_active' => 'boolean', 'meta' => 'array'];
}
```

Static calls such as `User::find(...)` are proxied to a fresh builder instance; `User::getBuilder()` returns that instance explicitly if you prefer to build a chain by hand.

---

## CRUD Operations

### Create a Model

```php
$model = User::create(['name' => 'John', 'email' => 'john@example.com'], $isProtected, $select);
```

- **$values** *(array)*: Data to initialize the model.
- **$isProtected** *(bool)*: Whether to hide guarded attributes. Default: `true`.
- **$select** *(array)*: Attributes to include in the result.

Returns the created model, or `false` on failure.

### Retrieve Models

#### Find a Model by ID

```php
$model = User::find($id, $isProtected);
```

Returns the model, or **`null`** when no row matches.

#### Find a Model or Execute Callback

```php
$model = User::findOr($id, $isProtected, function () {
    // Handle not found
});
```

#### Get All Models

```php
$models = User::all($isProtected, $select);
// or the alias:
$models = User::get($isProtected, $select);
```

Returns a **Collection** of models, or **`false`** when nothing matches.

---

### Update a Model

```php
$updatedModel = User::update(['key' => 'value'], $id, $isProtected);
```

### Delete a Model

```php
$isDeleted = User::delete($id);
```

A `delete()` with neither an id nor a `where()` filter is refused (it would delete every row). Models with soft deletes set `deleted_at` instead of removing the row; use `restore($id)` to bring one back.

---

## Query Building

### Basic Query Methods

#### Where Clauses

```php
$query = User::where('column', '=', 'value');
$query = User::whereIn('column', ['value1', 'value2']);
$query = User::whereLike('column', '%value%');
$query = User::whereNotIn('column', ['value1', 'value2']);
$query = User::whereNotLike('column', '%value%');
$query = User::whereBetween('column', [1, 10]);
$query = User::whereNotBetween('column', [1, 10]);

$query = User::whereLessThan('column', 'value');
$query = User::whereGreaterThan('column', 'value');
$query = User::whereLessThanOrEqual('column', 'value');
$query = User::whereGreaterThanOrEqual('column', 'value');
$query = User::whereEqual('column', 'value');
$query = User::whereNotEqual('column', 'value');

$query = User::whereNull('column');
$query = User::whereNotNull('column');

$query = User::orWhere('column', 'operatorOrValue', 'value');
$query = User::orWhereLike('column', 'value');
$query = User::orWhereNotLike('column', 'value');
$query = User::orWhereLessThan('column', 'value');
$query = User::orWhereGreaterThan('column', 'value');
$query = User::orWhereLessThanOrEqual('column', 'value');
$query = User::orWhereGreaterThanOrEqual('column', 'value');
$query = User::orWhereEqual('column', 'value');
$query = User::orWhereNotEqual('column', 'value');
$query = User::orWhereNull('column');
$query = User::orWhereNotNull('column');
```

Where clauses are chainable and terminate in a read such as `->get()`, `->first()`, or `->all()`:

```php
$recent = User::where('is_active', true)
    ->orderBy('created_at', 'DESC')
    ->limit(10)
    ->get();
```

#### Joins

```php
$query = User::join('roles', 'users.role_id', '=', 'roles.id');
$query = User::leftJoin('roles', 'users.role_id', '=', 'roles.id');
```

`rightJoin` and `fullOuterJoin` are also available.

#### Order By

`orderBy` is a chained (instance) method — call it after another builder method or via `getBuilder()`:

```php
$query = User::getBuilder()->orderBy('column', 'DESC');
```

#### Limit and Offset

```php
$query = User::limit(10)->offset(5);
```

---

## Collections

Multi-row reads (`all()`, `get()`, relation results, `paginate()->collection()`) return a `Eyika\Atom\Framework\Support\Collections\Collection` — a Laravel-like collection with 100+ chainable methods. Because a Collection is iterable, countable, and array-accessible, existing `foreach`/`count()`/`[]` usage keeps working while gaining the fluent API.

```php
$users = User::all();

$users->map(fn ($u) => $u->name);           // transform each item
$users->filter(fn ($u) => $u->is_active);    // keep matching items
$users->where('status', 'active');           // filter by attribute
$users->pluck('email');                      // extract a single column
$users->first();                             // first item (or null)
$users->sortBy('name');                      // sort ascending by key
$users->groupBy('role_id');                  // group into sub-collections
$users->reduce(fn ($carry, $u) => $carry + $u->points, 0);
$users->count();
$users->toArray();                           // back to a plain array
```

Other commonly used methods include `each`, `keyBy`, `unique`, `values`, `chunk`, `take`, `contains`, `sum`, `max`, `min`, `whereIn`, `firstWhere`, and `sortByDesc`.

### The `collect()` helper

Wrap any array (for example, the plain rows returned by the raw `DB` builder) in a Collection:

```php
$collection = collect($rows);
```

---

## Streaming Large Results

For large scans, stream rows one at a time straight from the database cursor instead of loading the whole result set into memory. `cursor()` (and its alias `lazy()`) return a `LazyCollection`, which exposes the same fluent API but yields lazily:

```php
User::where('is_active', true)
    ->cursor()
    ->each(function ($user) {
        // processed one row at a time — constant memory
    });

// lazy() is an alias for cursor()
foreach (User::lazy() as $user) {
    // ...
}
```

The cursor is forward-only / single-pass. Because rows are streamed, `with()` eager-loading is **not** applied to a cursor query.

---

## Aggregates

### Count

```php
$count = User::count('column');
```

### Average

```php
$average = User::avg('column');
```

### Max and Min

```php
$max = User::max('column');
$min = User::min('column');
```

Additional aggregates are available: `sum`, `group_concat`, `var_pop`, `stddev`, `bit_and`, `bit_or`, and `bit_xor`. Use `increment('column', $step)` / `decrement('column', $step)` for atomic counter updates.

---

## Pagination

```php
$paginated = User::paginate($currentPage, $recordsPerPage, $isProtected, $select);
```

- **$currentPage** *(int)*: Current page number.
- **$recordsPerPage** *(int)*: Number of records per page (default 15).
- **$isProtected** *(bool)*: Whether to hide guarded attributes.
- **$select** *(array)*: Attributes to include in the result.

`paginate()` returns a `PaginatedData` object (or `false` when there are no rows). It exposes the page items as a Collection plus paging metadata:

```php
$page = User::paginate(1, 15);

$page->collection();     // current page's items as a Collection
$page->each($callback);  // walk the items

$page->toArray();
// [
//   'data'          => [...],   // page items
//   'totalRecords'  => 128,
//   'totalPages'    => 9,
//   'recordsPerPage'=> 15,
//   'currentPage'   => 1,
//   'previousPage'  => null,    // URL, when applicable
//   'nextPage'      => '...?page=2',
// ]
```

---

## Model Events & Observers

Models fire lifecycle events around reads and writes:
`retrieved`, `creating`, `created`, `updating`, `updated`, `saving`, `saved`, `deleting`, `deleted` (plus `restoring` / `restored` for soft-delete restores). A "before" event (`creating`, `updating`, `saving`, `deleting`) whose listener returns `false` aborts the operation.

Register a listener by passing a callable to the matching static method:

```php
User::creating(function ($user) {
    $user->uuid = Str::uuid();
    // return false here to abort the insert
});

User::saved(function ($user) {
    // e.g. bust a cache
});
```

### Observers

Group related listeners into an **observer** class — a class whose methods are named after the events it wants to handle — and register it with `observe()`:

```php
class UserObserver
{
    public function creating($user) { /* ... */ }
    public function deleted($user)  { /* ... */ }
}

User::observe(UserObserver::class);
```

Only the event methods the observer actually defines are wired up. See [Events](../advanced/events.md) for the application-wide event dispatcher and cross-cutting listeners.

---

## Dynamic Methods

### Convert to Array

```php
$array = $model->toArray($guard, $select, $ignore);
```

- **$guard** *(bool)*: Whether to hide guarded attributes.
- **$select** *(array)*: Attributes to include.
- **$ignore** *(array)*: Attributes to exclude.

### Attach Related Models

```php
$user = User::getBuilder()->with('posts')->find(1);
$posts = $user->posts;
```

### Execute Raw SQL

```php
$result = $model->raw('SELECT * FROM table WHERE id = ?', [$id]);
```

---

This documentation covers essential methods of the Model Query Builder. For advanced use cases, refer to the source code or extend the base `Model` class.
