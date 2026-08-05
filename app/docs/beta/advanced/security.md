## Security In Atom

Security is a critical aspect of building robust and scalable applications. In this section, we will explore key security features provided by the framework and how to effectively implement them to safeguard your application.

### 1. **Authentication and Authorization**
   - **Authentication** verifies the identity of the user. Atom authenticates through *guards* — it ships session, token, and JWT guards out of the box.
   - **Authorization** determines what a user can and cannot do, controlling access to resources based on roles and permissions.

   The `Auth` class (`Eyika\Atom\Framework\Support\Auth\Auth`) is the entry point:
   ```php
   use Eyika\Atom\Framework\Support\Auth\Auth;

   if (Auth::attempt(['email' => $email, 'password' => $password])) {
       $user = Auth::user();
   }

   Auth::check();   // is someone authenticated?
   Auth::logout();  // end the session
   ```

   **Key Concepts:**
   - User authentication using guards (`SessionGuard`, `TokenGuard`, `JwtGuard`).
   - Credentials are verified with PHP's native `password_verify()` inside the auth drivers.
   - Middleware to protect routes and ensure the request is authenticated.

### 2. **Role and Permission Checks**
   Access can be restricted based on the roles or permissions a user holds. At the view layer, Blade's permission helper (`@can`) evaluates a user's permissions through the framework's `ValidatePermissions` authorization logic:

   ```php
   @can('edit-posts')
       <a href="/posts/{{ $post->id }}/edit">Edit</a>
   @endcan
   ```

   **Key Concepts:**
   - Assigning roles/permissions to users in your application layer.
   - Gating view fragments with `@can`.
   - Protecting routes with your own authorization middleware (see section 5).

### 3. **Custom Authentication Guards**
   Guards are configured in the `config/auth.php` file. Atom provides default guards, but you may define your own by implementing the `Authenticator` contract when the built-in behavior doesn't suit your needs.

   **Key Concepts:**
   - Custom guards to authenticate users based on different mechanisms (API tokens, JWT, OAuth, etc.).
   - Configuring guards and providers in `config/auth.php`.
   - Resolving a specific guard with `Auth::guard('name')`.

### 4. **Policy Scaffolding**
   Policies group the authorization logic for a given model or resource. You can scaffold a policy class with the framework's generator:

   ```bash
   php artisan make:policy PostPolicy
   ```

   **Key Concepts:**
   - Defining policy classes that hold per-action authorization rules.
   - Calling into the policy from your controllers before performing an action.
   - Keeping authorization logic out of controllers and views.

### 5. **Securing Routes with Middleware**
   Middleware is the primary way to enforce security checks before a request reaches your controller. Middleware is declared in `app/Http/Kernel.php` across the global stack, the `web`/`api` groups, and named aliases.

   The framework ships these security-relevant middleware:
   - `VerifyCsrfToken` — CSRF protection for state-changing requests.
   - `ValidateSignature` — rejects requests without a valid signed URL.
   - `EnsureEmailIsVerified` — blocks users whose email is unverified.
   - `ValidatePostSize`, `ConvertEmptyStringsToNull`, `SubstituteBindings`, `StartSession`, `ShareErrorsFromSession`, `ServePublicAssets`.

   Your application template adds `TrustProxies`, `HandleCors`, `EncryptCookies`, `PreventRequestsDuringMaintenance`, and `TrimStrings`.

   You enable a middleware by listing it in the appropriate group or attaching it to routes:
   ```php
   protected $middlewareGroups = [
       'web' => [
           StartSession::class,
           EncryptCookies::class,
           VerifyCsrfToken::class, // enable CSRF for browser routes
           SubstituteBindings::class,
       ],
   ];
   ```

### 6. **Password Hashing and Encryption**
   Atom keeps password hashing and two-way encryption separate.

   - **Password hashing:** Store passwords with PHP's native `password_hash()` and verify them with `password_verify()` — this is what the built-in auth drivers use (bcrypt by default).
   - **Two-way encryption:** Use the `Encrypter` facade for reversible encryption of values you need to read back. It uses `AES-256-CBC` with an HMAC integrity check, keyed on your `APP_KEY`.

   ```php
   use Eyika\Atom\Framework\Support\Facade\Encrypter;

   $ciphertext = Encrypter::encrypt($secret);
   $plaintext  = Encrypter::decrypt($ciphertext);

   // Serialize non-string values before encrypting:
   $ciphertext = Encrypter::encrypt($array, serialize: true);
   $array      = Encrypter::decrypt($ciphertext, unserialize: true);
   ```

   **Key Concepts:**
   - A valid `APP_KEY` is required — the `Encrypter` derives its key and HMAC from it.
   - The `EncryptCookies` middleware transparently encrypts and decrypts response/request cookies (excluding session and CSRF cookies).
   - The MAC is verified on decrypt, so tampered payloads throw instead of returning corrupt data.

### 7. **CSRF Protection**
   Cross-Site Request Forgery (CSRF) tricks an authenticated user's browser into making unwanted state-changing requests. Atom's `VerifyCsrfToken` middleware guards against this.

   **Key Concepts:**
   - The middleware verifies **all** state-changing verbs (`POST`, `PUT`, `PATCH`, `DELETE`) and exempts read-only ones (`GET`, `HEAD`, `OPTIONS`).
   - Add a token to your forms with the Blade directive:
     ```php
     <form method="POST" action="/profile">
         @csrf_token
         <!-- fields -->
     </form>
     ```
   - Exempt specific paths by adding them to the middleware's `$except` list (for example, an incoming webhook endpoint that authenticates by signature instead).

### 8. **Signed URLs**
   Signed URLs let you hand out tamper-proof links (email verification, one-time downloads) without a session. Generate them from a named route and validate them with the `ValidateSignature` middleware.

   ```php
   use Eyika\Atom\Framework\Support\Url;

   // Permanent signed URL
   $url = Url::signedRoute('unsubscribe', ['user' => $id]);

   // Expiring signed URL (Unix timestamp)
   $url = Url::temporarySignedRoute('verify.email', time() + 3600, ['user' => $id]);
   ```

   Within a handler you can also check the current request directly:
   ```php
   use Eyika\Atom\Framework\Exceptions\Http\AccessDeniedHttpException;

   if (!$request->hasValidSignature()) {
       throw new AccessDeniedHttpException('Invalid signature.');
   }
   ```

   Signatures are an HMAC-SHA256 over the path and sorted query string, keyed on `APP_KEY`; expired links are rejected automatically.

### 9. **Trusted Proxies and Trusted Hosts**

   `host()`, `scheme()`, `port()` and `clientIp()` will believe `X-Forwarded-*` headers **only**
   when the request arrived from an address you have named as a proxy. Nothing is trusted by
   default, and that default is deliberate: whatever you list here can set the client IP, host and
   scheme your application sees.

   ```dotenv
   # Literal IPs or CIDR blocks, comma separated. Empty means trust nothing.
   TRUSTED_PROXIES=10.0.0.0/8
   ```

   `TrustProxies` in `app/Http/Middlewares/` reads this. Trust is **per header** — narrow it when
   your proxy only sets some of them:

   ```php
   $request->setTrustedProxies(
       ['10.0.0.0/8'],
       Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO
   );
   ```

   That example believes the client IP and scheme but **not** `X-Forwarded-Host`, which matters if
   you resolve tenants or build URLs from the host — otherwise a caller can choose it. Pass `null`
   for all headers, `0` for none, or `Request::HEADER_X_FORWARDED_ALL`.

   > **Do not trust loopback "just to make it work."** Behind LiteSpeed and similar the PHP process
   > commonly sees `REMOTE_ADDR=127.0.0.1` for ordinary traffic, so trusting `127.0.0.1` trusts
   > every client. `'*'` trusts whatever peer connects and is only correct when something upstream
   > is guaranteed to strip inbound `X-Forwarded-*` headers.

   Separately, `TRUSTED_HOSTS` is a `Host` allowlist. When set, a request whose host is not listed
   falls back to `app.url` instead of being echoed into generated URLs — which is what stops a
   poisoned `Host` reaching password-reset links and emails. Leave it empty to disable the check;
   set it in production.

   ```dotenv
   TRUSTED_HOSTS=example.com,www.example.com
   ```

### Additional Security Best Practices:
   - **HTTPS:** Enforce HTTPS across your entire application to prevent man-in-the-middle attacks.
   - **Input Validation:** Always validate user input to prevent malicious data from being processed.
   - **Rate Limiting:** Use rate limiting to protect against brute force attacks.
   - **Session Security:** Regularly regenerate session tokens and enforce session expiration policies.
   - **Two-Factor Authentication (2FA):** Add an extra layer of security by requiring a second form of verification in addition to the password.

By following these best practices, you can ensure that your application remains secure and resilient against common security threats.
