## Testing

Testing is a crucial aspect of software development that ensures your application behaves as expected and allows you to catch bugs before they reach production. Atom is built on **PHPUnit** and ships **built-in integration testing**: a base test case boots your real application and dispatches fabricated requests through the full routing/middleware/response pipeline, so you can exercise routes end-to-end without a running web server.

### 1. **Testing Overview**
   Testing in Atom is powered by PHPUnit, a widely used testing framework for PHP. On top of plain PHPUnit the framework adds two integration base cases you extend:

   **Key Concepts:**
   - **Unit Testing:** Tests a single unit of functionality, typically a method or a class, in isolation.
   - **Integration (Feature) Testing:** Boots a real `Application` and dispatches fabricated requests through routing + middleware, asserting on the response. Provided by an integration base test case exposing `$this->get()`, `$this->post()`, `$this->postJson()` → a `TestResponse`.
   - **Database Testing:** A database base test case binds a real database connection and manages isolated tables per test, so DB-backed code runs against an actual database.

### 2. **Setting Up the Testing Environment**
   Tests live in the `tests/` directory and are configured with a `phpunit.xml` file. The suite is split into `Unit` and `Feature` test suites.

   **Key Concepts:**
   - **Test Directory Structure:** Organize tests into `Unit` (isolated classes) and `Feature` (integration tests) subdirectories under `tests/`.
   - **Test Configuration:** `phpunit.xml` configures the bootstrap file, test suites, and environment variables.

   Example `phpunit.xml`:
   ```xml
   <?xml version="1.0" encoding="UTF-8"?>
   <phpunit bootstrap="tests/bootstrap.php" colors="true">
       <testsuites>
           <testsuite name="Unit">
               <directory>tests/Unit</directory>
           </testsuite>
           <testsuite name="Feature">
               <directory>tests/Feature</directory>
           </testsuite>
       </testsuites>
   </phpunit>
   ```

   The bootstrap file loads the autoloader and points `base_path()` at your app root so the
   integration base case can boot the application. Example `tests/bootstrap.php`:
   ```php
   <?php

   require __DIR__ . '/../vendor/autoload.php';
   require_once __DIR__ . '/../vendor/eyika/atom-framework/src/helpers.php';

   $GLOBALS['base_path'] = dirname(__DIR__);
   ```

### 3. **Writing Tests**
   A plain unit test extends PHPUnit's `TestCase` and exercises a single class:

   ```php
   use PHPUnit\Framework\TestCase;

   class UserTest extends TestCase
   {
       public function test_full_name_joins_first_and_last(): void
       {
           $user = new User('John', 'Doe');
           $this->assertEquals('John Doe', $user->getFullName());
       }
   }
   ```

   An **integration (feature) test** extends your application's base `Test\TestCase` and dispatches requests through your app's **real** routes, middleware, and providers. Use `get()`, `post()`, `postJson()`, or `getJson()`; each returns a `TestResponse`:

   ```php
   namespace Test\Feature;

   use Test\TestCase;

   class HomeTest extends TestCase
   {
       public function test_the_home_page_renders(): void
       {
           $this->get('/')
               ->assertOk()
               ->assertBodyContains('Hello World');
       }

       public function test_a_named_route_reaches_the_controller(): void
       {
           $this->get('/name/Ada')
               ->assertOk()
               ->assertBodyContains('Hello Ada');
       }

       public function test_a_json_request_is_routed_to_the_api(): void
       {
           $this->getJson('/')
               ->assertOk()
               ->assertJsonFragment(['message' => 'hello world api']);
       }
   }
   ```

   Your app's base test case (in `tests/TestCase.php`) simply extends the framework's shipped integration base:

   ```php
   namespace Test;

   use Eyika\Atom\Framework\Support\Testing\TestCase as IntegrationTestCase;

   abstract class TestCase extends IntegrationTestCase
   {
       //
   }
   ```

   The base case boots your application once (providers, `RouteServiceProvider` maps, route files) and resets per-request state between calls, so tests stay isolated. Each dispatched request returns a `TestResponse` — call its assertion methods (see below) to verify the outcome.

### 4. **Assertions**
   Assertions compare the actual outcome with the expected outcome. Integration tests assert against the `TestResponse` returned by `get()`/`post()`/`postJson()`:

   **`TestResponse` assertions:**
   - **`assertOk()` / `assertCreated()` / `assertNotFound()`**: Assert the status code is 200 / 201 / 404.
   - **`assertStatus($code)`**: Asserts an exact status code.
   - **`assertBodyContains($needle)`**: Asserts the response body contains a substring.
   - **`assertBodyIs($expected)`**: Asserts the response body exactly equals a string.
   - **`assertJsonFragment($fragment)`**: Decodes a JSON body and asserts it contains the given key/value pairs.
   - **`assertHeader($name)`**: Asserts a header with the given name was sent.
   - **`json()`**: Returns the decoded JSON body as an array for custom assertions.

   Example:
   ```php
   $this->get('/api/users/1')
       ->assertJsonFragment(['id' => 1, 'name' => 'John']);
   ```

   Inside any test you also have the full set of PHPUnit assertions:
   - **`assertEquals($expected, $actual)`**, **`assertSame($expected, $actual)`**
   - **`assertTrue($condition)`**, **`assertFalse($condition)`**
   - **`assertNull($value)`**, **`assertIsArray($value)`**
   - **`assertCount($expectedCount, $array)`**

### 5. **Running Tests**
   You can run the suite through Atom's artisan command, Composer scripts, or PHPUnit directly.

   **Key Concepts:**
   - **Artisan Command:** `php artisan test` runs everything in the `tests/` directory.
   - **Composer Scripts:** `composer test` (all), `composer test:unit`, `composer test:feature`.
   - **PHPUnit Directly:** `./vendor/bin/phpunit`, optionally with `--testsuite Unit` or `--filter`.

   Example:
   ```bash
   php artisan test
   ```

   This runs all tests and outputs the results.

### 6. **Database Testing**
   For DB-backed code, extend the framework's database base test case. It boots the app, binds a real `Connection` to the container's `db.connection` slot (so models and the `DB` builder hit the database), and lets you set up and tear down **isolated tables** per test. It skips gracefully when the database is unavailable.

   **Key Concepts:**
   - **Isolated Schema:** Implement `createSchema()` and `dropSchema()` to build and drop dedicated test tables (conventionally prefixed, e.g. `atomtest_*`) around each test — no shared/production schema is touched.
   - **Raw SQL:** `$this->raw($sql)` runs statements against the test connection.
   - **Query Builder:** Use `DB::table(...)` and models exactly as you would in the app.

   Example:
   ```php
   use Eyika\Atom\Framework\Support\Database\DB;
   use Eyika\Atom\Framework\Support\Testing\DatabaseTestCase;

   class ItemTest extends DatabaseTestCase
   {
       protected function createSchema(): void
       {
           $this->raw('DROP TABLE IF EXISTS atomtest_items');
           $this->raw('CREATE TABLE atomtest_items (id INT AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50), qty INT)');
           $this->raw("INSERT INTO atomtest_items (name, qty) VALUES ('apple', 3)");
       }

       protected function dropSchema(): void
       {
           $this->raw('DROP TABLE IF EXISTS atomtest_items');
       }

       public function test_where_first_reads_a_row(): void
       {
           $row = DB::table('atomtest_items')->where('name', 'apple')->first();

           $this->assertIsArray($row);
           $this->assertSame('apple', $row['name']);
           $this->assertEquals(3, $row['qty']);
       }
   }
   ```

### 7. **Mocking and Stubbing**
   Mocking lets you simulate dependencies and isolate the component you're testing. Use PHPUnit's built-in mock builder, and swap the real service in the container with the mock via `instance()`.

   **Key Concepts:**
   - **Mock Objects:** Create a test double with `$this->createMock(...)`.
   - **Stubbing:** Define return values with `->method(...)->willReturn(...)`.
   - **Container Swapping:** Bind the mock into the container so resolved dependencies use it.

   Example:
   ```php
   $repo = $this->createMock(UserRepository::class);
   $repo->method('getUser')->willReturn(new User('John', 'Doe'));

   // Swap the real binding for the mock.
   $this->app->instance(UserRepository::class, $repo);
   ```

### 8. **Testing HTTP Requests**
   Integration tests simulate user interactions with your application. You can test routes, controllers, and API endpoints and assert on the responses. Requests are fabricated (from `$_SERVER`/`$_GET`/`$_POST`/cookies + an injectable source) and dispatched through the real pipeline — no HTTP server required.

   **Key Concepts:**
   - **`$this->get($uri, $headers = [])`**: Dispatch a GET request.
   - **`$this->post($uri, $body = [], $headers = [])`**: Dispatch a POST with a form body.
   - **`$this->postJson($uri, $json = [], $headers = [])`**: Dispatch a POST with a JSON body.
   - **`$this->call($method, $uri, $query, $body, $headers, $cookies, $json)`**: Full control over every part of the request.

   Example:
   ```php
   public function test_create_user_returns_created(): void
   {
       $this->withRoutes(function () {
           Route::post('/api/users', [UserController::class, 'create']);
       });

       $this->postJson('/api/users', ['name' => 'John Doe'])
           ->assertJsonFragment(['message' => 'User registered successfully']);
   }
   ```

### 9. **Testing State-Dependent Routes**
   Some routes depend on session state (for example, an authenticated user). The integration base case lets you bind a lightweight in-memory session double before dispatching, so protected routes can read the state they expect without a real session backend.

   **Key Concepts:**
   - **`$this->bindSession($data)`**: Bind an in-memory session pre-populated with the given data.
   - **`$this->bindRequest($method, $uri, $input, $headers)`**: Fabricate and bind a `Request` without dispatching (for code that resolves the current request via the facade).

   Example:
   ```php
   public function test_dashboard_reads_logged_in_user(): void
   {
       $this->bindSession(['user_id' => 1]);

       $this->withRoutes(function () {
           Route::get('/dashboard', fn($request) => 'welcome back');
       });

       $this->get('/dashboard')->assertBodyContains('welcome back');
   }
   ```

### 10. **Test Coverage**
   Code coverage shows which parts of your application are exercised by tests. PHPUnit can generate coverage reports (requires Xdebug or PCOV).

   **Key Concepts:**
   - **Code Coverage Reports:** Generate reports to visualize which lines of code are covered by tests.
   - **`--coverage-html` Option:** Generate a visual HTML report of test coverage.

   Example:
   ```bash
   ./vendor/bin/phpunit --coverage-html coverage
   ```

### Best Practices for Testing:
   - **Write Tests for Critical Code:** Focus on writing tests for code that is central to the application's functionality.
   - **Isolate Tests:** Keep unit tests free of external services; use mocks/stubs and the container to isolate behavior. Reserve real DB and full-pipeline exercise for the database/integration base cases.
   - **Use Descriptive Test Names:** Name your tests to describe their purpose and the behavior they are testing.
   - **Test Edge Cases:** Consider writing tests for edge cases, unexpected inputs, and failure scenarios.

By utilizing the testing tools and strategies in Atom, you can ensure that your application remains reliable, maintainable, and robust as it evolves.
