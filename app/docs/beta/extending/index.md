# Extending Atom

Atom is built to be extended without forking the framework. Every layer that needs application-specific behavior — the HTTP pipeline, the container, the console, the queue, the database layer, authentication — is exposed through a small interface or a factory-style registration point (`extend()`, a config map, or a class you drop into a conventional directory). You write a plain PHP class against that contract, register it, and the framework picks it up alongside its own built-ins. Nothing here requires touching `vendor/eyika/atom-framework`.

This section walks through each extension point in turn: what interface or base class to implement, where to register it, and a worked example.

## In this section

- [Custom Middleware](middleware) — implement `MiddlewareInterface` to inspect or short-circuit requests/responses, and register it on the HTTP Kernel.
- [Service Providers](service-providers) — bootstrap bindings and wire up subsystems by extending `ServiceProvider` and listing it in `config/app.php`.
- [Custom Commands](commands) — extend `Command` to add your own console commands, auto-discovered from `app/Console/Commands`.
- [Custom Jobs](jobs) — use the `ShouldQueue` trait to push slow work onto the queue and process it with `queue:work`.
- [Database Grammars](database-grammars) — extend `Grammar` to teach the schema builder and query layer a new SQL dialect, registered via `GrammarFactory::extend()`.
- [Auth Guards](auth-guards) — implement the `Authenticator` contract to add a new authentication mechanism alongside the built-in session, token, and JWT guards.

Each page is self-contained, but they share a common shape: an interface or abstract base class from the framework, a registration hook (a config array or an `extend()` call), and a convention for where the class lives. Once you have written one extension, the others will feel familiar.
