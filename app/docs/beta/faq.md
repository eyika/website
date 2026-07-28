## Frequently Asked Questions (FAQ)

### 1. **What is this framework?**
   This is a lightweight PHP framework designed to provide developers with a robust set of tools to build web applications. It includes core features like routing, request handling, a service container, event system, and more. The goal is to provide a minimalistic yet powerful base for building custom applications without unnecessary bloat.

### 2. **Who is this framework for?**
   This framework is intended for PHP developers who prefer flexibility and control over their application structure. It’s suitable for developers who want to avoid the overhead of larger frameworks and need a solution that allows for deep customization and extendability.

### 3. **Do I need to have prior knowledge of a PHP framework?**
   While prior experience with PHP frameworks (like Laravel, Symfony, or CodeIgniter) can be helpful, it is not required. This framework provides foundational concepts like routing, service containers, and middleware, which are common in modern PHP frameworks.

### 4. **Is there any documentation available?**
   Yes! The documentation is organized to guide you through the various concepts, from basic setup to more advanced topics like security, caching, testing, and event systems. Check out the full documentation for all the details.

### 5. **How do I install this framework?**
   You can install the framework via Composer. To get started, run the following command:
   ```
   composer create-project eyika/atom my-app dev-main
   ```
   This will install the framework and set up the basic folder structure. See the [Getting Started](getting-started#installation) guide for the full setup flow (`.env`, `key:generate`, serving the app).

### 6. **Can I use this framework for a production application?**
   Yes, Although this framework is still at **beta** stage, it is designed for production-ready applications and currently there are currently two production apps powered by Atom <a href="https://backtestfx.com" target="_blank">Backtestfx</a> and <a href="https://sendmani.com" target="_blank">Sendmani</a>. However, it’s recommended to perform thorough testing and review the available features before deploying your application to ensure it meets your needs.

### 7. **How do I contribute to the framework?**
   Contributions are always welcome! Please refer to the [Contributing](contributing) section in the documentation for detailed instructions on how to fork the repository, create pull requests, and ensure your changes adhere to the project's standards.

### 8. **What are some key features of this framework?**
   Some of the core features include:
   - **App-Owned Routing:** Route maps + matchers (declared in your `RouteServiceProvider`) that support parameterized and named routes.
   - **Service Container:** A powerful container for dependency injection and service management (bind/singleton/scoped/instance, tagging, aliasing).
   - **Event System:** An event-driven architecture with string/object events, wildcard listeners, and model events + observers.
   - **Collections:** Query results come back as fluent `Collection`s (map/filter/pluck/groupBy/…), with lazy cursors for large datasets.
   - **Testing Tools:** Built-in integration testing that boots a real app and dispatches fabricated requests through the full pipeline.
   - **Caching Mechanism:** PSR-6 caching with array, file, database, redis, apc, memcached and dynamodb drivers.

### 9. **Does this framework support testing?**
   Yes. Beyond plain PHPUnit unit tests, the framework ships built-in integration testing: a base test case boots a real (fixture) application and dispatches fabricated requests through the full routing/middleware pipeline (`$this->get('/x')`, `->post()`, `->postJson()`), and a database test case runs against a real database with isolated tables. Run everything with `php artisan test`. For more information, check the [Testing](advanced/testing) section in the documentation.

### 10. **Is there built-in security?**
   Yes, security is a key consideration of this framework. We provide features such as CSRF protection, encryption, and secure authentication systems. For more details, please refer to the [Security](advanced/security) section in the documentation.

### 11. **Can I use this framework with a database?**
   Yes, the framework can be easily integrated with a database. You can define models, handle database migrations, and interact with your data through the query builder or raw SQL queries. Query builder and model reads return fluent `Collection`s, and `->cursor()`/`->lazy()` stream large result sets. See the [Database](database/index) section for more information on working with databases.

### 12. **What are the benefits of using this framework?**
   - **Flexibility:** This framework is highly customizable, allowing you to build your application exactly how you need it.
   - **Minimalistic:** It doesn’t include unnecessary bloat, so you only use the components you need.
   - **Extensible:** You can extend or override the core functionality to meet your project’s specific requirements.

### 13. **Can I use this framework with Vue.js or other frontend frameworks?**
   Yes, this PHP framework can be easily used alongside frontend frameworks like Vue.js, React, or Angular. It is designed to work with any modern frontend architecture, allowing you to create dynamic and responsive user interfaces.

### 14. **How do I handle sessions in this framework?**
   Sessions are handled using PHP’s native session management, but you can also integrate more advanced session handling mechanisms such as Redis or database-backed sessions. For more information, check the [Session](#) section.

### 15. **What databases are supported?**
   The framework is database-agnostic and can be used with any database supported by PDO, including MySQL, PostgreSQL, SQLite, and others. You can integrate any database engine of your choice by configuring the connection details in the configuration files.

### 16. **Where can I find more resources?**
   In addition to the documentation, we also have an active community forum where you can ask questions, share tips, and learn from other developers. You can also find tutorials and guides on the project's website. 

### 17. **How can I contact support?**
   If you need assistance, feel free to open an issue on the <a href="https://github.com/eyika" target="_blank">GitHub organization</a>. We strive to respond to queries promptly.

---

If you have any other questions or need further clarification, don’t hesitate to reach out!