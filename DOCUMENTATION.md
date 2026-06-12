# Cherry RESTful API Handler — Documentation

> **Requires PHP 8.1+**

---

## Table of Contents

1. [Initialization order](#initialization-order)
2. [Public API Reference](#public-api-reference)
3. [Configuration](#configuration)
4. [Routing](#routing)
5. [Authentication](#authentication)
6. [CORS](#cors)
7. [Request Input](#request-input)
8. [Response Behaviour](#response-behaviour)
9. [Error Handling](#error-handling)
10. [Security Features](#security-features)
11. [Best Practices](#best-practices)
12. [HTTP Status Code Reference](#http-status-code-reference)
13. [Full Usage Example](#full-usage-example)

---

## Initialization Order

Call the static methods in this order:

```php
CherryRESTfulAPI::setBasePath('/api'); // must come first if used
CherryRESTfulAPI::init();              // reads URI, method, and body
CherryRESTfulAPI::setAuthToken(...);
CherryRESTfulAPI::setDebugMode(...);
CherryRESTfulAPI::setMaxInputSize(...);
CherryRESTfulAPI::enableCORS(...);
CherryRESTfulAPI::addRoute(...);       // register as many as needed
CherryRESTfulAPI::processRequest();    // dispatch — call once, last
```

`setBasePath` must precede `init` because the base path is stripped during URI parsing.

---

## Public API Reference

| Method | Access | Description |
|--------|--------|-------------|
| `setBasePath(string $path)` | public static | Strip a URL prefix before route matching. Call before `init()`. |
| `init()` | public static | Parse method, URI, and body. Call once at startup. |
| `setAuthToken(string $token)` | public static | Set the Bearer token for protected routes. |
| `setDebugMode(bool $debug)` | public static | Expose exception details in 500 responses (dev only). |
| `setMaxInputSize(int $bytes)` | public static | Cap request body size. Default: 1 MB. |
| `enableCORS(string\|array $origins)` | public static | Enable CORS; optionally restrict to specific origins. |
| `addRoute(string $method, string $path, callable $handler, bool $requiresAuth)` | public static | Register a route. |
| `processRequest()` | public static | Match the current request to a route and dispatch. Call once, last. |
| `respond(int $code, mixed $data)` | public static | Send a custom-status response from inside a handler. Terminates. |
| `getInput()` | public static | Return the decoded request body, or null. |
| `getQueryParams()` | public static | Return all `$_GET` query-string parameters as an array. |
| `getParam(string $key, mixed $default)` | public static | Return a single query-string parameter, or `$default`. |

---

## Configuration

### `setBasePath(string $basePath)`

```php
CherryRESTfulAPI::setBasePath('/api');
```

Strips the given prefix from every request URI before route matching. Use this when the API lives in a sub-directory. Must be called before `init()`.

Pair with the matching `RewriteBase` (Apache) or `location` block path (Nginx) in your server config. See [server-configs/](server-configs/) for ready-made examples.

---

### `init()`

```php
CherryRESTfulAPI::init();
```

Must be the first call after `setBasePath`. Reads `$_SERVER['REQUEST_METHOD']` and `REQUEST_URI`, strips the base path, splits the path into segments, and decodes the request body.

---

### `setAuthToken(string $token)`

```php
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));
```

Sets the Bearer token checked on routes where `$requiresAuth = true`. Load from an environment variable or a secrets manager — **never hard-code a real token**. An empty string disables authentication (all auth-required routes return 401).

---

### `setDebugMode(bool $debug)`

```php
CherryRESTfulAPI::setDebugMode(getenv('APP_DEBUG') === 'true');
```

When `true`, unhandled exceptions thrown by handlers are serialized into 500 responses (message, file, line). In production this is always `false` — only a generic `"Internal server error"` message is returned. **Never enable in public-facing environments.**

---

### `setMaxInputSize(int $bytes)`

```php
CherryRESTfulAPI::setMaxInputSize(256_000); // 250 KB
```

Limits how many bytes are read from `php://input`. Requests exceeding this limit receive a **413 Payload Too Large** response before the handler is invoked. Default: **1 048 576 bytes (1 MB)**.

---

## Routing

### `addRoute(string $method, string $path, callable $handler, bool $requiresAuth = false)`

```php
CherryRESTfulAPI::addRoute('GET', '/users/{id}', function (string $id): array {
    return ['id' => $id];
});
```

| Parameter | Type | Description |
|-----------|------|-------------|
| `$method` | `string` | HTTP verb: `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, etc. Case-insensitive. |
| `$path` | `string` | URI path. Use `{name}` for dynamic segments. Leading/trailing slashes are stripped. |
| `$handler` | `callable` | Invoked when the route matches. Dynamic segments are passed as positional `string` arguments in the order they appear in the path. Should return a JSON-encodable value, or call `respond()` for a custom status. |
| `$requiresAuth` | `bool` | When `true`, the request must carry a valid `Authorization: Bearer <token>` header. Default: `false`. |

#### Dynamic segments

```
/users/{id}                    → handler($id)
/users/{id}/posts/{postId}     → handler($id, $postId)
```

Segment values are URL-decoded path components (`[^/]+`). Always validate and cast them inside the handler before use.

#### Route matching

Routes are evaluated in registration order. The first matching route wins; subsequent routes with the same method and path are unreachable. When a path matches but no registered method does, a **405 Method Not Allowed** is returned with an `Allow` header listing valid methods. HEAD is automatically added to `Allow` when GET is registered.

---

### `setBasePath(string $path)` *(see Configuration)*

---

## Authentication

```php
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): void {
    CherryRESTfulAPI::respond(204);
}, requiresAuth: true);
```

When `$requiresAuth` is `true`, `processRequest` checks the `Authorization` header before invoking the handler:

```
Authorization: Bearer <token>
```

The comparison uses [`hash_equals()`](https://www.php.net/hash_equals), which runs in constant time regardless of where the strings first differ, preventing timing-based side-channel attacks.

If the header is missing or the token does not match, the handler is **never called** and a **401 Unauthorized** response is returned.

---

## CORS

```php
// Allow any origin
CherryRESTfulAPI::enableCORS('*');

// Allow specific origins only (recommended)
CherryRESTfulAPI::enableCORS(['https://app.example.com', 'https://admin.example.com']);
```

When enabled, `processRequest` emits:

```
Access-Control-Allow-Origin: <origin>
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, Accept
Access-Control-Max-Age: 86400
```

OPTIONS preflight requests receive an empty **204 No Content** response immediately — no handler is invoked.

When an explicit origin list is used, `Vary: Origin` is also emitted so shared caches do not serve one origin's response to another.

---

## Request Input

### `getInput(): ?array`

```php
$body = CherryRESTfulAPI::getInput();
$name = $body['name'] ?? null;
```

Returns the decoded request body as an associative array, or `null` when the body is absent or unparseable. Does **not** fall back to `$_POST` when the body is present but contains invalid JSON — this prevents silent data substitution.

### `getQueryParams(): array`

```php
$params = CherryRESTfulAPI::getQueryParams(); // ['page' => '2', 'sort' => 'name']
```

Returns all query-string parameters as an associative array (`$_GET`).

### `getParam(string $key, mixed $default = null): mixed`

```php
$page = (int) CherryRESTfulAPI::getParam('page', 1);
$sort = CherryRESTfulAPI::getParam('sort', 'id');
```

Returns a single query-string parameter, or `$default` when the key is absent.

---

## Response Behaviour

All responses carry `Content-Type: application/json` and the security hardening headers described under [Security Features](#security-features).

### Default status codes

| Scenario | Status |
|----------|--------|
| Route matched, handler returns a value | **200 OK** |
| OPTIONS preflight | **204 No Content** |
| HEAD request on a known GET route | **200 OK** (no body) |
| Auth required, token missing or wrong | **401 Unauthorized** |
| Path matched, method not registered | **405 Method Not Allowed** |
| No route matched | **404 Not Found** |
| Body exceeds size limit | **413 Payload Too Large** |
| Unhandled exception in handler | **500 Internal Server Error** |

### `respond(int $statusCode, mixed $data = null): never`

```php
CherryRESTfulAPI::addRoute('POST', '/users', function (): void {
    // ... create user ...
    CherryRESTfulAPI::respond(201, ['id' => 42]);
});

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): void {
    // ... delete ...
    CherryRESTfulAPI::respond(204); // no body
}, requiresAuth: true);
```

Call `respond()` from inside a handler to send any HTTP status code. Terminates execution immediately. When a handler returns a value normally (without calling `respond()`), status **200** is used automatically.

---

## Error Handling

Unhandled exceptions thrown by route handlers are caught by `processRequest`. The handler never crashes the server.

- **Production** (`setDebugMode(false)`): returns `{"error": "Internal server error"}` with status 500. No exception detail is exposed.
- **Development** (`setDebugMode(true)`): returns the exception message, file, and line number in the 500 response body.

```php
CherryRESTfulAPI::setDebugMode(getenv('APP_DEBUG') === 'true');
```

---

## Security Features

### Timing-safe token comparison

`hash_equals()` is used instead of `===` so that the comparison takes the same amount of time regardless of where the strings diverge. This prevents an attacker from deducing the token one character at a time by measuring response times.

### Payload size limit

`php://input` is read with a hard byte cap (`setMaxInputSize`). Oversized payloads are rejected before any decoding or handler invocation, mitigating memory exhaustion and slow-POST attacks.

### Exception containment

All handler calls are wrapped in a `try / catch (Throwable)` block. Uncaught exceptions never reach the client as raw PHP errors; the client always receives a controlled JSON response.

### Hardening headers (every response)

| Header | Value | Effect |
|--------|-------|--------|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-type sniffing. |
| `X-Frame-Options` | `DENY` | Blocks embedding in iframes. |
| `Referrer-Policy` | `no-referrer` | Omits `Referer` on cross-origin requests. |
| `Cache-Control` | `no-store` | Prevents caching of API responses. |

### Regex safety in route matching

Static path segments are escaped with `preg_quote()` before being compiled into a match regex. Dots, parentheses, and other metacharacters in route definitions cannot alter the compiled pattern or match unintended paths.

### `getallheaders()` fallback

On CGI and FastCGI servers where `getallheaders()` is unavailable, headers are reconstructed from `$_SERVER`. The lookup is also case-insensitive, ensuring consistent behaviour across web server configurations.

### 405 vs 404 distinction

When a path is recognised but the HTTP method is not registered for it, a **405 Method Not Allowed** is returned (not 404). The `Allow` header in the response tells the client which methods are valid, which is required by RFC 9110.

### HEAD method support

HEAD requests are matched against GET routes automatically. The handler is not invoked and no body is sent, complying with the HTTP specification which requires HEAD support on any resource that accepts GET.

### Empty token guard

If `setAuthToken` is never called or is called with an empty string, `isAuthenticated()` returns `false` immediately. Auth-required routes always respond with 401 rather than inadvertently allowing access.

---

## Best Practices

- **Use environment variables for secrets**: `setAuthToken(getenv('API_SECRET'))` — never commit a token to source control. See `.env.example` for setup options.
- **Validate dynamic segments inside handlers**: path params are raw URL strings; cast and validate them before querying a database or performing any operation.
- **Return correct status codes**: use `respond(201, ...)` for resource creation, `respond(204)` for deletion, `respond(422, ...)` for validation errors.
- **Run behind HTTPS**: the handler does not enforce HTTPS. Configure TLS at the web server or load-balancer level. See [server-configs/](server-configs/) for ready-made HTTPS configs.
- **Avoid wildcard CORS in production**: use an explicit origin allowlist when the API serves authenticated requests.
- **Disable debug mode in production**: set `APP_DEBUG=false` (or simply never call `setDebugMode(true)`).
- **Check JSON input is present**: call `getInput()` and check for `null` in POST / PUT / PATCH handlers before accessing fields.
- **Use `setMaxInputSize`** to limit the accepted payload to what your largest legitimate request actually needs.

---

## HTTP Status Code Reference

| Code | Meaning | When sent |
|------|---------|-----------|
| 200 | OK | Route matched and handler returned successfully. |
| 201 | Created | Call `respond(201, $data)` from a POST handler. |
| 204 | No Content | OPTIONS preflight, HEAD requests, or `respond(204)` from a handler. |
| 401 | Unauthorized | Auth-required route; token absent or incorrect. |
| 404 | Not Found | No registered route matched the request path. |
| 405 | Method Not Allowed | Path matched but no route for this HTTP method. |
| 413 | Payload Too Large | Request body exceeds `setMaxInputSize` limit. |
| 422 | Unprocessable Entity | Call `respond(422, $errors)` for validation failures. |
| 500 | Internal Server Error | Unhandled exception thrown by a handler. |

---

## Full Usage Example

```php
<?php

require_once 'Cherry_RESTful_API_Handler.php';

// 1. Configure — setBasePath must come before init()
CherryRESTfulAPI::setBasePath(getenv('APP_BASE_PATH') ?: '');
CherryRESTfulAPI::init();

CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));
CherryRESTfulAPI::setDebugMode(getenv('APP_DEBUG') === 'true');
CherryRESTfulAPI::setMaxInputSize(256_000);
CherryRESTfulAPI::enableCORS(['https://app.example.com']);

// 2. Register routes
CherryRESTfulAPI::addRoute('GET', '/users', function (): array {
    $page  = (int) CherryRESTfulAPI::getParam('page', 1);
    $limit = (int) CherryRESTfulAPI::getParam('limit', 20);
    return ['users' => [], 'page' => $page, 'limit' => $limit];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('GET', '/users/{id}', function (string $id): array {
    $id = (int) $id;
    if ($id <= 0) {
        CherryRESTfulAPI::respond(422, ['error' => 'Invalid id']);
    }
    return ['id' => $id, 'name' => 'Alice'];
});

CherryRESTfulAPI::addRoute('POST', '/users', function (): void {
    $body = CherryRESTfulAPI::getInput();
    if (empty($body['name'])) {
        CherryRESTfulAPI::respond(422, ['error' => 'name is required']);
    }
    // ... persist user ...
    CherryRESTfulAPI::respond(201, ['id' => 99, 'name' => $body['name']]);
});

CherryRESTfulAPI::addRoute('PUT', '/users/{id}', function (string $id): array {
    $body = CherryRESTfulAPI::getInput();
    return ['updated' => true, 'id' => (int) $id];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): void {
    // ... delete user ...
    CherryRESTfulAPI::respond(204);
}, requiresAuth: true);

// 3. Dispatch
CherryRESTfulAPI::processRequest();
```
