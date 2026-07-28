## Authorization In Atom

Authorization determines what an authenticated user is allowed to do. Atom draws a deliberate line here: it ships a full **authentication** system (guards, drivers, roles) but it does **not** ship a Laravel-style `Gate`/policy *enforcement* runtime. There is no `Gate` facade, no `$this->authorize(...)` helper, and no framework `can` middleware. Authorization in Atom is done with **role/permission checks** and **middleware you own**, plus a `make:policy` generator that scaffolds a plain class for you to call yourself.

This page documents exactly what exists so you can wire authorization correctly instead of reaching for APIs that aren't there.

---

### 1. **What the framework provides (and what it does not)**

Provided by the framework:

- **Authentication** via the `Auth` class and its guards — see [Security](advanced/security).
- **Role helpers** on the auth layer (`Auth::roleIs()`, `roleIsNot()`, `verifyRole()`), backed by the `ManageRoles` trait.
- **`make:policy`** — a scaffold generator (a stub class, no runtime behind it).

**Not** provided:

- No `Gate` class. (There is a commented-out `// 'Gate' => Gate::class` line in the facade registry — it is intentionally disabled and there is no class behind it.)
- No abstract `Policy` base class, no policy auto-resolution, no `authorize()` / `denies()` / `allows()` runtime.
- No framework-shipped `can`/`authorize` middleware.

Everything below is built from these pieces. If you expect Gate/policy enforcement to "just work" by convention, it will not — you invoke your authorization logic explicitly.

---

### 2. **Role checks with the Auth layer**

The auth layer mixes in the `ManageRoles` trait (`Eyika\Atom\Framework\Support\Auth\Concerns\ManageRoles`), which exposes static role checks against the authenticated user:

```php
use Eyika\Atom\Framework\Support\Auth\Auth;

// Auth::roleIs(user, role) — true when the user's role matches.
if (Auth::roleIs($request->auth_user, 'admin')) {
    // ...
}

// The inverse:
if (Auth::roleIsNot($request->auth_user, ['editor', 'author'])) {
    // ...
}
```

Both accept a single role string **or** an array of roles. Under the hood `verifyRole()` loads the user's role by `user->role_id` from a `Role` model and compares against the role's `name`:

```php
public static function verifyRole($user, $_role)
{
    $_role = Arr::wrap($_role);

    if (!$role = Role::getBuilder()->orderBy()->findBy('id', $user->role_id)) {
        return false;
    }
    return Arr::exists($_role, $role->name);
}
```

> This relies on your application defining an `App\Models\Role` model and a `role_id` column on the user. The trait references `App\Models\Role` directly — the role storage schema lives in your application, not the framework.

---

### 3. **Enforcing authorization with middleware**

Because there is no `can` middleware in the framework, route-level authorization is enforced with **application middleware**. This is the primary, recommended pattern. A production app (fx-data-server) ships two examples you can copy.

**Role middleware** — variadic role list, checks each against the authenticated user:

```php
namespace App\Http\Middlewares;

use Closure;
use Eyika\Atom\Framework\Http\BaseResponse;
use Eyika\Atom\Framework\Http\Contracts\MiddlewareInterface;
use Eyika\Atom\Framework\Http\Request;
use Eyika\Atom\Framework\Support\Auth\Auth;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;
use Eyika\Atom\Framework\Support\Facade\Response;

class RoleMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next, string ...$roles): BaseResponse
    {
        foreach ($roles as $role) {
            if (!Auth::roleIs($request->auth_user, $role)) {
                return $request->wantsJson()
                    ? JsonResponse::forbidden("You can't perform this action")
                    : Response::back()->withErrors(['error' => "You can't perform this action"]);
            }
        }

        return $next($request);
    }
}
```

Middleware receives its parameters from the route's `:`-suffix syntax (see [Middleware](middleware)):

```php
Route::get('/admin/users', [UserController::class, 'index'])->middleware('role:admin');
```

**Permission middleware** — checks required permissions against a list resolved from the request/session:

```php
class PermissionMiddleware implements MiddlewareInterface
{
    public function __construct(protected array $permissions) {}

    public function handle(Request $request, Closure $next): BaseResponse
    {
        $userPermissions = $request->user_permissions ?? [];

        foreach ($this->permissions as $permission) {
            if (!in_array($permission, $userPermissions)) {
                return Response::plain('Forbidden', BaseResponse::STATUS_FORBIDDEN);
            }
        }

        return $next($request);
    }
}
```

> The permission middleware's `getUserPermissions()` is a **placeholder** — it reads `$request->user_permissions`. You are responsible for populating that (e.g. from an earlier auth middleware that hydrates the user's permissions). Treat it as a starting template, not a finished feature.

Register either one as an alias in `app/Http/Kernel.php` so routes can reference it by a short name — the mechanics are covered in [Middleware](middleware).

---

### 4. **Policy scaffolding (`make:policy`)**

`make:policy` is a **scaffold generator**, not a wiring mechanism. It writes a plain class to `app/Policies/` and does nothing else — there is no discovery, no binding, and nothing calls it for you.

```bash
php artisan make:policy PostPolicy
```

The generated stub:

```php
<?php

namespace App\Policies;

class PostPolicy
{
    /**
     * Example ability check.
     */
    public function view($user, $model): bool
    {
        return false;
    }
}
```

You add ability methods, then **call the policy yourself** from a controller before performing the action:

```php
use App\Policies\PostPolicy;

public function update(Request $request, int $id)
{
    $post = Post::find($id);

    if (!(new PostPolicy())->update($request->auth_user, $post)) {
        return JsonResponse::forbidden('Not allowed to edit this post.');
    }

    // ... proceed ...
}
```

This keeps per-model authorization logic in one place. There is no `authorize()` helper that resolves the policy by convention — the wiring is manual and explicit.

---

### 5. **View-layer gating (`@can`)**

At the template layer, Atom's Blade-style engine provides a `@can` helper that evaluates a permission through the framework's `ValidatePermissions` authorization logic:

```php
@can('edit-posts')
    <a href="/posts/{{ $post->id }}/edit">Edit</a>
@endcan
```

Use this to hide UI a user isn't permitted to act on. It is a convenience for rendering — it does **not** protect the underlying route. Always back a `@can`-gated action with server-side enforcement (section 3).

---

### 6. **Recommended approach**

Putting it together, an Atom application authorizes like this:

1. **Authenticate** the request (auth middleware attaches `$request->auth_user`).
2. **Enforce coarse access** with role/permission middleware on routes or groups.
3. **Enforce fine-grained, per-record rules** by calling a policy class (or an inline check) inside the controller before mutating data.
4. **Gate UI fragments** with `@can` for a clean interface — never as the only line of defense.

Because the framework leaves enforcement to your application, keep authorization checks close to the action they protect and never rely solely on hidden UI.
