# Welcome to Atom Documentation

Atom is a powerful, modern PHP framework designed to simplify and streamline web development. With a robust router, intuitive APIs, and comprehensive features, it's the perfect tool for developers of all levels.  
  
This framework is designed to mimic Laravel as closely as possible, providing a familiar and powerful development experience. Whether you're building a small application or a large-scale enterprise system, Atom offers the tools and features you need to get the job done efficiently.

---

## Features at a Glance
- 🌟 **App-Owned Routing:** Wire requests to route files with route maps + matchers in your `RouteServiceProvider` — no hardcoded web/api heuristic.
- ⚡ **Worker-Safe Performance:** Per-request state is flushed between requests, so the framework runs under persistent workers (Octane-style) as well as classic FPM.
- 🛠️ **Extensible & Modular:** App-owned service providers, deferred providers, and zero-config package auto-discovery.
- 🧪 **Built-in Testing Support:** Boot a real app and dispatch fabricated requests through the full routing/middleware pipeline.
- 🚌 **MVC Architecture**: Organize your code using the Model-View-Controller pattern for clean and maintainable applications.
- 🚂 **Routing**: Define routes to handle incoming requests and direct them to the appropriate controllers.
- 📚 **Middleware**: App-level and framework middleware, grouped and prioritized via the HTTP `Kernel`.
- 🚎 **Controllers**: Manage your application's logic and handle requests.
- 🚎 **Requests**: Validate and process incoming data (with an injectable request source for testing).
- 💻 **Views**: Render dynamic content using the Blade templating engine.
- 💥 **Logging**: Keep track of application events and errors.
- 🍔 **Database & Collections**: A fluent query builder and models that return powerful `Collection`s (map/filter/pluck/groupBy/…) plus lazy cursors for large results.
- 🔌 **Service Container**: Bind/singleton/scoped/instance bindings, tagging, aliasing, and automatic dependency injection.
- ⚡ **Event System**: String and object events, wildcard listeners, subscribers, and model events with observers.
- ✨ **Advanced Features**: Security (CSRF, encryption, signed URLs), PSR-6 caching, testing, and more.

---

## Getting Started
To get started with Atom Framework, follow these steps:

1. [Install Atom Framework](getting-started#installation)
2. [Learn About the Routing System](routing)
3. [Explore Middleware](middleware)
4. [Understand the Database Features](database/index)

---

## Need Help?
If you have questions or encounter issues, feel free to check out:
- Have questions? Check out the [FAQ](faq) section for answers to common questions and troubleshooting tips.
- Or head on to our [Community Forum](#)

## Contributing

We welcome contributions from the community! If you'd like to contribute, please read the [Contributing](contributing) section for guidelines on how to get involved.

Thank you for choosing this framework for your PHP development needs. We hope you enjoy using it as much as we enjoyed building it!