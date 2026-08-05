# Responses In Atom

## Introduction

Every controller action and middleware in Atom returns a response object that the framework sends back to the client. Atom ships two response classes — `Response` for views, HTML, redirects, files, and proxying, and `JsonResponse` for structured JSON payloads with sensible status-code helpers — both of which extend a shared `BaseResponse` that handles headers, cookies, status codes, and the actual send. You can build these either through the `Response` / `JsonResponse` **facades** or through the `response()` / `json_response()` **helper functions**; both resolve to the exact same underlying instance for the current request.

---

## Table of Contents

1. [Getting a Response Instance](#getting-a-response-instance)
2. [Returning Views](#returning-views)
3. [Plain Text, HTML, Custom, and Image Responses](#plain-text-html-custom-and-image-responses)
4. [JSON Responses](#json-responses)
5. [Status Codes](#status-codes)
6. [Headers](#headers)
7. [Cookies](#cookies)
8. [Redirects](#redirects)
9. [File Downloads](#file-downloads)
10. [Proxying Requests](#proxying-requests)
11. [Content Negotiation (HTML vs JSON)](#content-negotiation-html-vs-json)
12. [Carrying Errors and Old Input on Redirect](#carrying-errors-and-old-input-on-redirect)
13. [The Response Lifecycle](#the-response-lifecycle)
14. [Best Practices](#best-practices)

---

## Getting a Response Instance

There are two equivalent ways to get a response object: the **facades** (the same pattern used throughout the rest of these docs) or the global **helper functions**.

```php
use Eyika\Atom\Framework\Support\Facade\Response;
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

// Facades — static calls proxied to the underlying instance
return Response::view('home', ['name' => 'Ada']);
return JsonResponse::ok('users list', $users);
```

```php
// Helper functions — return the same underlying instance directly
return response()->view('home', ['name' => 'Ada']);
return json_response()->ok('users list', $users);
```

`response()` returns `Response::getInstance()` and `json_response()` returns `JsonResponse::getInstance()` — there is no difference between calling through the facade or through the helper, so pick whichever reads better at the call site. All the examples below show both forms for the more common calls and switch to just one form afterwards for brevity.

> A controller method should **return** the response object — don't call `->send()` yourself. The router sends it for you after your action (and any middleware) returns, which is also what lets middleware inspect or modify the response on the way out. See [Controllers](controllers) and [Middleware](middleware).

---

## Returning Views

`view()` queues a template for compilation instead of rendering immediately — the actual render happens during [send](#the-response-lifecycle), and only if the request isn't going to be treated as a JSON/XHR request (see [Content Negotiation](#content-negotiation-html-vs-json)).

```php
use Eyika\Atom\Framework\Support\Facade\Response;

public function home()
{
    return Response::view('home', ['name' => 'Ada']);
    // or: return response()->view('home', ['name' => 'Ada']);
}
```

`view(string $file_name, array $data = [])` looks up the template under `resource_path('views')`. Which template engine renders it depends on the `view.use_advance_engine` config value — the built-in Blade engine when it's truthy, or the Twig-based engine otherwise. See [Views](views) for template syntax and configuration.

If compiling the view throws, the response body is replaced with `"Server Error: ..."` and the status is forced to `500` — a broken template never crashes the request.

---

## Plain Text, HTML, Custom, and Image Responses

`Response` has a family of small helpers for non-view, non-JSON bodies. They all share the same underlying implementation, just with a different default MIME type:

```php
use Eyika\Atom\Framework\Support\Facade\Response;

Response::plain('pong');                              // text/plain, 200
Response::html('<h1>Hi</h1>');                         // text/html, 200
Response::custom('<xml/>', 200, 'application/xml');    // any MIME type you choose
Response::image($binaryJpegData, 200, 'jpeg');         // image/jpeg
```

- `plain(string $message, int $statusCode = 200): self`
- `html(string $message, int $statusCode = 200): self`
- `custom(string $message, int $statusCode = 200, $mime = 'text/plain'): self`
- `image(string $data, int $statusCode = 200, string $type = "jpeg"): self` — sets `Content-Type: image/{$type}`

Every one of these sets `Content-Type: {mime}; charset=utf-8` and the given status code, and turns off view compilation (so calling `->view()` earlier in the same chain is discarded).

---

## JSON Responses

### `Response::json()`

`Response` itself has a generic `json()` method for one-off payloads:

```php
use Eyika\Atom\Framework\Support\Facade\Response;

return Response::json(['greeting' => 'Hello, world!'], 200);
```

`json(array $data, int $statusCode = 200): self` JSON-encodes `$data`, sets `Content-Type: application/json; charset=utf-8`, and sets the status code.

Any status code in the `100`–`599` range is accepted; anything outside it throws an `Exception`.

> **Changed**: `json()` previously accepted only a fixed set of codes — `200, 204, 201, 304, 400, 401, 402, 403, 404, 422, 500` — and threw for anything else, including `409` and `502` even though both have helpers on `JsonResponse`. That restriction is gone.

### `JsonResponse` helpers

For API responses, `JsonResponse` gives you one method per common status code, each wrapping the payload in a consistent `{"message": ..., "data": ...}` or `{"message": ..., "errors": ...}` shape. Every helper takes the message first, then the data/errors:

```php
use Eyika\Atom\Framework\Support\Facade\JsonResponse;

public function store(Request $request)
{
    $validated = $request->validate(['email' => 'required|email']);
    if (!$validated) {
        return JsonResponse::badRequest('validation failed', Validator::$errors);
    }

    $user = User::create($validated);
    return JsonResponse::created('user created', $user);
    // or: return json_response()->created('user created', $user);
}
```

| Method | Status | Signature |
|---|---|---|
| `ok()` | 200 | `ok(string $message, mixed $data = [])` |
| `created()` | 201 | `created(string $message = '', mixed $data = [])` |
| `noContent()` | 204 | `noContent()` |
| `badRequest()` | 400 | `badRequest(string $message = '', array $errors = [])` |
| `unauthorized()` | 401 | `unauthorized(string $message = 'Unauthorized')` |
| `paymentRequired()` | 402 | `paymentRequired(string $message = '', array $errors = [])` |
| `forbidden()` | 403 | `forbidden(string $message = '', array $errors = [])` |
| `notFound()` | 404 | `notFound(string $message, mixed $data = null)` |
| `conflict()` | 409 | `conflict(string $message = '', array $errors = [])` |
| `unprocessableEntity()` | 422 | `unprocessableEntity(string $message = 'unprocessable request', string $errors = '')` |
| `tooManyRequests()` | 429 | `tooManyRequests(string $message = 'Too many requests', int\|null $retryAfter = null, array $errors = [])` |
| `serverError()` | 500 | `serverError(string $message = '')` |
| `badGateway()` | 502 | `badGateway(string $message = '')` |
| `serviceUnavailable()` | 503 | `serviceUnavailable(string $message = 'Service unavailable', int\|null $retryAfter = null, array $errors = [])` |

`tooManyRequests()` and `serviceUnavailable()` take an optional `$retryAfter` **in seconds**, which
is emitted as the `Retry-After` header. Send it whenever you know when the limit or outage clears —
without it a client has nothing to back off against and will usually just retry immediately.

```php
return JsonResponse::tooManyRequests('Rate limit exceeded', retryAfter: 60);
```

```php
use Eyika\Atom\Framework\Support\Facade\JsonResponse;
use Eyika\Atom\Framework\Exceptions\Db\ModelNotFoundException;

public function show(Request $request, int $id)
{
    try {
        $user = User::findOrFail($id);
        return JsonResponse::ok('user found', $user);
    } catch (ModelNotFoundException $e) {
        return JsonResponse::notFound('user not found');
    }
}
```

> `notFound()`'s second parameter is keyed `errors` in the response body (`['message' => ..., 'errors' => $data]`), not `data` — despite the parameter being named `$data`. `ok()` and `created()`, by contrast, key it `data`.

Every `JsonResponse` payload is passed through automatic object-to-array conversion before encoding: any object in the payload with a `toArray()` or `__toArray()` method, or implementing `JsonSerializable`, is converted; anything else falls back to a plain array cast. This is why you can hand `ok()`/`created()` a `Model` or a `Collection` directly instead of manually calling `->toArray()`. Circular references (e.g. a relation pointing back at its parent) are detected and replaced with the string `"Circular Reference Detected"` instead of recursing forever.

---

## Status Codes

Set (or override) the status code explicitly with `status()`:

```php
use Eyika\Atom\Framework\Support\Facade\Response;

return Response::view('home')->status(201);
```

`BaseResponse` defines the status codes it knows about as constants, mainly used internally by the helpers above but available for your own use:

```php
BaseResponse::STATUS_OK;                    // 200
BaseResponse::STATUS_NO_CONTENT;            // 204
BaseResponse::STATUS_CREATED;               // 201
BaseResponse::STATUS_MOVED_PERMANENTLY;     // 301
BaseResponse::STATUS_FOUND;                 // 302
BaseResponse::STATUS_SEE_OTHER;             // 303
BaseResponse::STATUS_NOT_MODIFIED;          // 304
BaseResponse::STATUS_BAD_REQUEST;           // 400
BaseResponse::STATUS_UNAUTHORIZED;          // 401
BaseResponse::STATUS_PAYMENT_REQUIRED;      // 402
BaseResponse::STATUS_FORBIDDEN;             // 403
BaseResponse::STATUS_NOT_FOUND;             // 404
BaseResponse::STATUS_CONFLICT;              // 409
BaseResponse::STATUS_UNPROCESSABLE_ENTITY;  // 422
BaseResponse::STATUS_TOO_MANY_REQUESTS;     // 429
BaseResponse::STATUS_INTERNAL_SERVER_ERROR; // 500
BaseResponse::STATUS_BAD_GATEWAY;           // 502
BaseResponse::STATUS_SERVICE_NOT_AVAILABLE; // 503
```

---

## Headers

Set a header with `setHeader()`, which is chainable and can be called as many times as you need:

```php
use Eyika\Atom\Framework\Support\Facade\Response;

return Response::json($data)
    ->setHeader('X-Total-Count', (string) $count)
    ->setHeader('Cache-Control', 'no-store');
```

`setHeader(string $key, string $content, int|null $code = null, bool $replace = true): self`

- `$replace` mirrors PHP's `header()` `$replace` argument — pass `false` to add a header alongside an existing one of the same name instead of overwriting it (useful for headers PHP allows to repeat).
- `$code` optionally forces the HTTP status code as the header is emitted (rarely needed — prefer `status()`).

---

## Cookies

`setCookie()` builds a `Cookie` object internally and queues it to be sent as a `Set-Cookie` header:

```php
use Eyika\Atom\Framework\Support\Facade\Response;

return Response::view('dashboard')
    ->setCookie('session_id', $sessionId, time() + 3600);
```

```php
public function setCookie(
    $name,
    $value = '',
    $expiry = 0,
    $path = '/',
    $domain = '',
    $secure = false,
    $httpOnly = true,
    $sameSite = 'Lax'
)
```

- `$expiry` is an **absolute Unix timestamp** (e.g. `time() + 86400`), not a duration — the framework converts it to both an `Expires` date and a `Max-Age` duration for you. `0` (the default) means a session cookie with no `Expires`/`Max-Age` at all.
- `$httpOnly` defaults to `true` here (unlike the underlying `Cookie` class, which defaults it to `false`) — client-side JS can't read a cookie set this way unless you explicitly pass `false`.
- `$sameSite` accepts `'Lax'`, `'Strict'`, `'None'`, or `''` to omit the attribute entirely; any other value is normalized away to `''`.

To delete a cookie, set it with an expiry in the past:

```php
Response::setCookie('session_id', '', time() - 3600);
```

Every queued cookie is reachable via `cookies()`, which returns an `Arrayable` collection of `Cookie` objects.

---

## Redirects

```php
use Eyika\Atom\Framework\Support\Facade\Response;

return Response::redirect('/login');
return Response::redirect('/login', 301);              // custom status code
return Response::redirect('/login', 302, 3);            // 3-second delayed redirect
```

`redirect(string $to, int $code = self::STATUS_FOUND, int|null $delay = null): self`

- Without `$delay`, this sets a `Location` header with the given status (default `302 Found`).
- With `$delay`, it instead sets a `Refresh: {delay}; URL={to}` header (an HTML meta-refresh style delayed redirect), still at the given status code.
- CR/LF characters are stripped from `$to` before use, to prevent header-injection via a redirect target built from user input.

There is also a global `redirect()` helper that calls `Response::redirect()` directly:

```php
return redirect('/login');
return redirect('/login', 301, 3);
```

### Redirecting back

```php
return Response::back();
return Response::back(303, 2); // custom status + delay
```

`back(int $code = self::STATUS_SEE_OTHER, int|null $delay = null)` redirects to the `Referer` header **only if it's same-origin** as the current request — a cross-origin or missing `Referer` falls back to `Route::previous()` or `/`, so `back()` can never be turned into an open redirect by a spoofed header.

---

## File Downloads

```php
use Eyika\Atom\Framework\Support\Facade\Response;

public function download()
{
    return Response::download('/path/to/file.txt', 'downloaded-file.txt');
}
```

`download(string $file_path, string|null $file_name = null): self`

- Resolves `$file_path` with `realpath()` (collapsing any `../`), and if `config('filesystem.download_base')` is set, the resolved path must live under that directory — otherwise the download is rejected. This closes off path-traversal when `$file_path` is influenced by user input.
- If the file doesn't exist or isn't confined to the configured base directory, it returns a `404` with a plain-text `File not found.` body instead of throwing.
- `$file_name` (or the real file's basename if omitted) is sanitized — CR/LF and `"` are stripped — before being placed in the `Content-Disposition` header, since it also flows from user input in some flows.
- Sets `Content-Type: application/octet-stream`, `Content-Disposition: attachment; filename="..."`, `Content-Length`, and cache-busting headers (`Cache-Control: must-revalidate`, `Expires: 0`, `Pragma: public`).
- The actual bytes are streamed with `readfile()` at send time rather than being loaded into the response body up front.

---

## Proxying Requests

`Response::proxy()` forwards the current request to another host and relays its response back to the client — useful for a thin backend-for-frontend endpoint.

```php
use Eyika\Atom\Framework\Support\Facade\Response;
use Eyika\Atom\Framework\Http\Request;

public function proxyToUpstream(Request $request)
{
    return Response::proxy($request, 'https://api.upstream.example.com/v1/data');
}
```

`proxy(Request $request, ?string $target = null, array $extraHeaders = [])` — passing `$target` immediately builds and **sends** a `Proxy`, returning the resulting `Response`. Omitting `$target` returns a `Proxy` instance you can point somewhere with `->to($target, $extraHeaders)`:

```php
return Response::proxy($request)->to('https://api.upstream.example.com/v1/data');
```

Proxying has built-in SSRF protections in `Proxy::assertSafeTarget()`, which every target URL is checked against before the request is made:

- The target must be an `http://` or `https://` URL.
- If `config('proxy.allowed_hosts')` is a non-empty list, the target host **must** be in it (an explicit allowlist becomes the sole trust anchor).
- Otherwise, the host is resolved and rejected if it's a private, loopback, or reserved IP (e.g. `127.0.0.1`, `169.254.169.254`, `10.0.0.0/8`) — closing off SSRF to internal infrastructure and cloud metadata endpoints.
- Redirects from the upstream are **not** followed (`follow_location = 0`), since a `30x` to an internal host would otherwise bypass the same check.

Sensitive request headers are stripped before forwarding by default — `Authorization`, `Proxy-Authorization`, `Cookie`, `X-CSRF-Token`, `X-XSRF-Token` (case-insensitive) — configurable via `config('proxy.blacklist')`. `Host` is always dropped. `$extraHeaders` are merged in afterward and can override anything forwarded from the original request.

---

## Content Negotiation (HTML vs JSON)

At [send time](#the-response-lifecycle), the framework decides how to finish the response based on the incoming `Request`, via `Request::isNotHtml()` / `isHtml()`:

- `isNotHtml()` is true when the request either **wants JSON** (`Accept: application/json`, or a JSON request body via `Content-Type: application/json`) **or** is an XHR request (`X-Requested-With: XMLHttpRequest`).
- When `isNotHtml()` is true, the response is sent **as-is** — whatever is currently in `body()` — without compiling any queued view.
- Otherwise (a normal browser navigation), a queued `view()` is compiled to HTML before sending.

```php
use Eyika\Atom\Framework\Support\Facade\Request;

if (Request::isNotHtml()) {
    return JsonResponse::ok('user found', $user);
}
return Response::view('users.show', ['user' => $user]);
```

> **Gotcha**: calling `->view()` on a request that `isNotHtml()` is a no-op as far as the client sees — the view is never compiled, and the client gets whatever the response body currently is (empty, unless you also set one). If a route needs to serve both a browser page and a JSON API, branch on `Request::isNotHtml()` (or `wantsJson()`) rather than assuming `view()` always renders.

File responses and redirects are sent before content negotiation is even considered — a `download()` or `redirect()` always takes effect regardless of `Accept`/`X-Requested-With`.

---

## Carrying Errors and Old Input on Redirect

When a redirect response is sent, any errors, validation errors, or input attached to it are stashed into the [session](routing) for the next request to read (typically to re-render a form with its previous values and validation messages):

```php
use Eyika\Atom\Framework\Support\Facade\Response;

public function store(Request $request)
{
    $validated = $request->validate(['email' => 'required|email']);
    if (!$validated) {
        return Response::back()
            ->withValidationErrors(Validator::$errors)
            ->withInputs();
    }

    // ...
}
```

- `withErrors(array $errors): self` — merges into a general error bag (session key `errors`).
- `withValidationErrors(array $validationErrors): self` — session key `validation_errors`.
- `withInputs(): self` — captures the current request's input (`Request::input()`) into the session key `old_inputs`, so a Blade/Twig view can repopulate form fields.

These are only written to the session when the response `isRedirect` — calling them on a `view()` or `json()` response has no effect on the session.

---

## The Response Lifecycle

A controller normally just **returns** a response; the router calls `send()` on it once your action (and any middleware) has run. You rarely need to call these yourself, but they're useful to know when debugging:

- `send(): bool` — sends status, headers, and body (or streams a file, or persists redirect-only session data) exactly once; a second call is a no-op.
- `sendDeferred(): void` — same as `send()`, but does **not** mark the response as sent, allowing a later `send()` (or another `sendDeferred()`) to run again. Used internally where output needs to be flushed early without closing out the response.
- `terminate(): self` — marks the response as already sent without actually sending anything, causing a subsequent `send()` call to be skipped.
- `responseSent(): bool` — whether `send()`/`terminate()` has already run for this response.

Under a persistent worker (rather than classic PHP-FPM), the framework can capture status/headers/body internally instead of calling `http_response_code()`/`header()`/`echo` directly — toggle with `BaseResponse::captureOutput(bool $capture = true)` and read the result back with the static `BaseResponse::capturedStatus()` / `capturedHeaders()` / `capturedBody()`, or per-instance `sentStatus()` / `sentHeaders()` / `sentBody()`. Application code built on Atom generally doesn't need to touch this — it's the mechanism a worker runtime uses to relay a response instead of writing straight to PHP's output buffer.

---

## Best Practices

1. **Prefer the specific `JsonResponse` helper over `Response::json()`** for API endpoints — `ok()`/`created()`/`notFound()`/etc. give you a consistent response envelope (`message` + `data`/`errors`) for free, and don't require memorizing which status codes `json()` will accept.
2. **Return, don't send.** Let the router call `send()` — calling it yourself inside a controller risks double-sending or interferes with middleware that wants to inspect/modify the response afterward.
3. **Branch on `Request::isNotHtml()`** for endpoints that must serve both a browser page and an API client, rather than assuming `view()` is always rendered.
4. **Never build `Response::download()`'s `$file_path` from unsanitized user input** without also configuring `filesystem.download_base` — the traversal guard only applies when that config key is set.
5. **Always pass an explicit `$target` (or a strict `proxy.allowed_hosts` allowlist) to `Response::proxy()`** rather than trusting a target derived from request input, even though SSRF protections are built in.

---

## Conclusion

The `Response` and `JsonResponse` facades (and their `response()`/`json_response()` helper equivalents) cover everything a controller needs to send back to the client — views, plain/HTML/custom bodies, JSON with consistent status-code helpers, redirects that carry errors and old input, file downloads, and even proxying to an upstream service — while `BaseResponse` handles the shared plumbing of status codes, headers, cookies, and content negotiation underneath. See [Controllers](controllers) for how these fit into a full request/response cycle, [Requests](requests) for the input side, and [Middleware](middleware) for inspecting or altering a response before it's sent.