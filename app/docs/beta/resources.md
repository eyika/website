## API Resources In Atom

API resources give you a single place to define how a model (or any value) is transformed into the array/JSON your API returns — keeping that shaping out of your controllers. In Atom this is a **lightweight, scaffold-based** feature: `make:resource` generates a small transformer class that you fill in and call yourself. Be clear on the scope up front — Atom does **not** ship a `JsonResource` base class or a resource *runtime*. There is no automatic wrapping, no `->response()` integration, no collection/pagination engine, and nothing resolves resources by convention. A resource is a plain class with a `toArray()` method that you invoke explicitly.

---

### 1. **Generating a resource**

```bash
php artisan make:resource UserResource
```

This writes a class to `app/Http/Resources/`:

```php
<?php

namespace App\Http\Resources;

class UserResource
{
    public function __construct(protected mixed $resource)
    {
    }

    /**
     * Transform the resource into an array.
     */
    public function toArray(): array
    {
        return (array) $this->resource;
    }
}
```

That is the entire feature the framework provides: a constructor that takes the value to transform and a `toArray()` that, by default, casts it to an array. There is no parent class and no framework machinery behind it — customize it freely.

---

### 2. **Shaping the output**

Replace the default `toArray()` with an explicit mapping so your API returns exactly the fields you intend (and never leaks sensitive columns):

```php
<?php

namespace App\Http\Resources;

class UserResource
{
    public function __construct(protected mixed $resource)
    {
    }

    public function toArray(): array
    {
        return [
            'id'         => $this->resource->id,
            'name'       => $this->resource->name,
            'email'      => $this->resource->email,
            'created_at' => $this->resource->created_at,
            // password, tokens, etc. are simply never included
        ];
    }
}
```

---

### 3. **Using a resource in a controller**

Because there is no runtime, you instantiate the resource and call `toArray()` yourself, then return it through the JSON response:

```php
use App\Http\Resources\UserResource;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

public function show(Request $request, int $id)
{
    $user = User::find($id);

    return JsonResponse::ok('User fetched', (new UserResource($user))->toArray());
}
```

For a list, map over the collection — there is no dedicated resource-collection class, so a simple `array_map` is the idiomatic approach:

```php
public function index()
{
    $users = User::getBuilder()->get();

    $data = array_map(
        fn ($user) => (new UserResource($user))->toArray(),
        $users
    );

    return JsonResponse::ok('Users fetched', $data);
}
```

---

### 4. **Scope and expectations**

To set expectations plainly, here is what this feature **is not**:

- No `JsonResource` / `ResourceCollection` base classes.
- No automatic response wrapping (no top-level `data` key unless you add it).
- No conditional attributes (`when()`, `whenLoaded()`), relationship merging, or pagination metadata.
- Nothing calls `toArray()` for you — resources are never auto-resolved from a return value.

Think of an Atom resource as a **hand-rolled transformer with a generated skeleton**. It exists to give your response-shaping a consistent home; all wiring is explicit and under your control. If you need richer behavior (conditional fields, wrapping, collections), implement those methods on the class yourself — the framework won't do it implicitly.
