# Model Query Builder In Atom

The Model Query Builder provides a fluent interface for interacting with your database models, enabling you to perform CRUD operations and build complex queries.

---

## Table of Contents

1. [Initialization](#initialization)
2. [CRUD Operations](#crud-operations)
3. [Query Building](#query-building)
4. [Model Events](#model-events)
5. [Aggregates](#aggregates)
6. [Pagination](#pagination)
7. [Dynamic Methods](#dynamic-methods)

---

## Initialization

### Creating a Model Instance

```php
$model = new Model(['key' => 'value'], $childModel);
```

- **$values** *(array)*: Initial attributes for the model.
- **$child** *(self | UserModelInterface)*: Optional child model.

---

## CRUD Operations

### Create a Model

```php
$model = Model::getBuilder()->create(['key' => 'value'], $isProtected, $select);
```

- **$values** *(array)*: Data to initialize the model.
- **$isProtected** *(bool)*: Whether to hide protected attributes. Default: `true`.
- **$select** *(array)*: Attributes to include in the result.

### Retrieve Models

#### Find a Model by ID

```php
$model = Model::getBuilder()->find($id, $isProtected);
```

#### Find a Model or Execute Callback

```php
$model = Model::getBuilder()->findOr($id, $isProtected, function () {
    // Handle not found
});
```

#### Get All Models

```php
$models = Model::getBuilder()->all($isProtected, $select);
```

---

### Update a Model

```php
$updatedModel = Model::getBuilder()->update(['key' => 'value'], $id, $isProtected);
```

---

### Delete a Model

```php
$isDeleted = Model::getBuilder()->delete($id);
```

---

## Query Building

### Basic Query Methods

#### Where Clauses

```php
$query = Model::getBuilder()->where('column', '=', 'value');
$query = Model::getBuilder()->whereIn('column', ['value1', 'value2']);
$query = Model::getBuilder()->whereLike('column', '%value%');
$query = Model::getBuilder()->whereNo('column', 'value');
$query = Model::getBuilder()->whereNotIn('column', ['value1', 'value2']);
$query = Model::getBuilder()->whereNotLike('column', '%value%');

$query = Model::getBuilder()->whereLessThan('column', 'value');
$query = Model::getBuilder()->whereGreaterThan('column', 'value');
$query = Model::getBuilder()->whereLessThanOrEqual('column', 'value');
$query = Model::getBuilder()->whereGreaterThanOrEqual('column', 'value');
$query = Model::getBuilder()->whereEqual('column', 'value');
$query = Model::getBuilder()->whereNotEqual('column', 'value');

$query = Model::getBuilder()->whereNull('column');
$query = Model::getBuilder()->whereNotNull('column');
$query = Model::getBuilder()->orWhere('column', 'operatorOrValue', 'value');
$query = Model::getBuilder()->orWhereLike('column', 'value');
$query = Model::getBuilder()->orWhereNotLike('column', 'value');
$query = Model::getBuilder()->orWhereLessThan('column', 'value');
$query = Model::getBuilder()->orWhereGreaterThan('column', 'value');

$query = Model::getBuilder()->orWhereLessThanOrEqual('column', 'value');
$query = Model::getBuilder()->orWhereGreaterThanOrEqual('column', 'value');
$query = Model::getBuilder()->orWhereEqual('column', 'value');
$query = Model::getBuilder()->orWhereNotEqual('column', 'value');
$query = Model::getBuilder()->orWhereNull('column');
$query = Model::getBuilder()->orWhereNotNull('column');
$query = Model::getBuilder()->orWhereGreaterThan('column', 'value');
```

#### Order By

```php
$query = Model::getBuilder()->orderBy('column', 'DESC');
```

#### Limit and Offset

```php
$query = Model::getBuilder()->limit(10)->offset(5);
```

---

## Aggregates

### Count

```php
$count = Model::getBuilder()->count('column');
```

### Average

```php
$average = Model::getBuilder()->avg('column');
```

### Max and Min

```php
$max = Model::getBuilder()->max('column');
$min = Model::getBuilder()->min('column');
```

---

## Pagination

```php
$paginated = Model::getBuilder()->paginate($currentPage, $recordsPerPage, $isProtected, $select);
```

- **$currentPage** *(int)*: Current page number.
- **$recordsPerPage** *(int)*: Number of records per page.
- **$isProtected** *(bool)*: Whether to hide protected attributes.
- **$select** *(array)*: Attributes to include in the result.

---

## Model Events

- **boot**: Triggered when the model is initialized.
- **creating**: Called before a model is created.
- **created**: Called after a model is created.
- **saving**: Triggered before saving a model.
- **saved**: Triggered after saving a model.
- **deleting**: Called before a model is deleted.
- **deleted**: Called after a model is deleted.

Example:

```php
Model::getBuilder()->creating($model, 'event', function ($model) {
    // Logic before creating the model
});
```

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
$model->with('RelatedModel');
```

### Execute Raw SQL

```php
$result = $model->raw('SELECT * FROM table WHERE id = ?', [$id]);
```

---

This documentation covers essential methods of the Query Builder for managing database models. For advanced use cases, refer to the source code or extend the base `Model` class.