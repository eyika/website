# Advanced Concepts Documentation Index

Welcome to the Advanced Concepts section! This page focuses on the critical areas of the framework that enhance its performance, scalability, and maintainability. Below is the organized index for quick navigation.

---

## 1. **Security**
   See [Security](security).
   - Authentication and Authorization
   - Role-Based Access Control (RBAC)
   - Policy-Based Authorization
   - Securing Routes with Middleware
   - Password Hashing and Encryption
   - CSRF Protection and Validation

## 2. **Caching**
   See [Caching](caching).
   - PSR-6 cache and the `Cache` facade
   - Cache Drivers and Configuration (array, file, database, redis, apc, memcached, dynamodb)
   - Storing, Retrieving, and Forgetting Items
   - Managing Cache Expiry and Eviction

## 3. **Testing**
   See [Testing](testing).
   - Integration Testing through the full routing/middleware pipeline
   - Fabricated requests: `$this->get()`, `->post()`, `->postJson()` and the `TestResponse`
   - Database Testing against a real database with isolated tables
   - Testing Events and Listeners

## 4. **Service Container**
   See [Service Container](service-container).
   - Binding Services (`bind`, `singleton`, `instance`, `scoped`)
   - Service Resolution and Automatic Dependency Injection (`make`)
   - Aliases, Extending/Decorating, Tagging, and Method Injection (`call`)
   - Request Scopes and Worker Safety

## 5. **Event System**
   See [Events](events).
   - String events and object events with payloads
   - Creating Event Listeners (closures, `Class@method`, invokable classes)
   - Wildcard listeners, `until()`, `hasListeners()`, and `forget()`
   - The `EventServiceProvider` `$listen` map and subscribers
   - Model events and observers
   - Event Broadcasting

---

### Quick Links

- [Getting Started Guide](../getting-started)
- [Configuration](../configuration)

---

### Feedback and Contributions
If you have suggestions or would like to contribute, please check out our [contribution guide](../contributing).
