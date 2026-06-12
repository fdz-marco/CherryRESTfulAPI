# Cherry RESTful API Handler — Documentation

> **Requires PHP 8.1+**

---

## Table of Contents

1. [Public API Reference](#public-api-reference)
2. [Configuration](#configuration)
3. [Routing](#routing)
4. [Authentication](#authentication)
5. [CORS](#cors)
6. [Request Input](#request-input)
7. [Response Behaviour](#response-behaviour)
8. [Security Features](#security-features)
9. [Best Practices](#best-practices)
10. [HTTP Status Code Reference](#http-status-code-reference)
11. [Full Usage Example](#full-usage-example)

---

## Public API Reference

| Method | Access | Description |
|--------|--------|-------------|
| `init()` | public static | Parse the request method, URI, and body. Call once before everything else. |
| `setAuthToken(string $token)` | public static | Set the Bearer token used for protected routes. |
| `setMaxInputSize(int $bytes)` | public static | Cap the accepted request body size. Default: 1 048 576 (1 MB). |
| `enableCORS(string\|array $origins)` | public static | Enable CORS; optionally restrict to specific origins. |
| `addRoute(string $method, string $path, callable $handler, bool $requiresAuth)` | public static | Register a route. |
| `getInput()` | public static | Return the decoded request body, or null. |
| `processRequest()` | public static | Match the current request to a route and dispatch. Call once, last. |
| `buildRouteRegex(string $path)` | private static | Convert a `{param}` path to a regex. |
| `isAuthenticated()` | private static | Timing-safe Bearer token check. |
| `getAllRequestHeaders()` | private static | Retrieve headers with CGI fallback. |
| `sendCORSHeaders()` | private static | Emit CORS headers if enabled. |
| `sendSecurityHeaders()` | private static | Emit hardening headers on every response. |
| `parseInput()` | private static | Read, size-check, and JSON-decode the request body. |
| `sendResponse(int $code, mixed $body)` | private static | JSON-encode and send the response, then exit. |

---

## Configuration

### `init()`

```php
CherryRESTfulAPI::init();
```

Must be the first call. Reads `$_SERVER['REQUEST_METHOD']` and `REQUEST_URI`, then decodes the request body. Call it before `setAuthToken`, `enableCORS`, `addRoute`, and `processRequest`.

---

### `setAuthToken(string $token)`

```php
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));
```

Sets the Bearer token checked on routes where `$requiresAuth = true`.

- Load from an environment variable or a secrets manager — **never hard-code a real token**.
- An empty string disables authentication entirely (all auth-required routes will return 401).

---

### `setMaxInputSize(int $bytes)`

```php
CherryRESTfulAPI::setMaxInputSize(512_000); // 500 KB
```

Limits how many bytes are read from `php://input`. Any request body larger than this limit gets a **413 Payload Too Large** response before the handler is invoked.

Default: **1 048 576 bytes (1 MB)**.

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
| `$path` | `string` | URI path. Use `{name}` for dynamic segments. Leading/trailing slashes are stripped automatically. |
| `$handler` | `callable` | Invoked when the route matches. Dynamic segments are passed as positional `string` arguments in the order they appear in the path. Must return a JSON-encodable value. |
| `$requiresAuth` | `bool` | When `true`, the request must carry a valid `Authorization: Bearer <token>` header. Default: `false`. |

#### Dynamic segments

```
/users/{id}           → one capture  → handler($id)
/users/{id}/posts/{postId} → two captures → handler($id, $postId)
```

Segment values are URL-decoded path components (`[^/]+`). Validate and cast them inside the handler before use.

#### Route matching

Routes are evaluated in registration order. The first matching route wins; subsequent routes with the same method and path are unreachable.

---

## Authentication

```php
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): array {
    return ['deleted' => $id];
}, requiresAuth: true);
```

When `$requiresAuth` is `true`, `processRequest` checks the `Authorization` header:

```
Authorization: Bearer <token>
```

The comparison uses [`hash_equals()`](https://www.php.net/hash_equals), which runs in constant time regardless of where the strings first differ, preventing timing-based side-channel attacks.

If the header is missing or the token does not match, the handler is **never called** and a `401 Unauthorized` response is returned immediately.

---

## CORS

```php
// Allow all origins (not recommended for credentialed requests)
CherryRESTfulAPI::enableCORS('*');

// Allow specific origins
CherryRESTfulAPI::enableCORS(['https://app.example.com', 'https://admin.example.com']);
```

When enabled, `processRequest` emits:

```
Access-Control-Allow-Origin: <origin>
Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS
Access-Control-Allow-Headers: Authorization, Content-Type, Accept
Access-Control-Max-Age: 86400
```

OPTIONS preflight requests receive an empty **204 No Content** response and do not reach any registered handler.

When an explicit origin list is used, a `Vary: Origin` header is also emitted so that shared caches do not serve one origin's response to a different origin.

---

## Request Input

### `getInput(): ?array`

```php
$data = CherryRESTfulAPI::getInput();
$name = $data['name'] ?? null;
```

Returns the decoded request body as an associative array, or `null` when:

- The body is empty or absent (falls back to `$_POST` if populated).
- The body contains invalid JSON (returns `null`; does **not** silently fall back to `$_POST`).
- The body exceeds the configured size limit (terminates with 413 before returning).

---

## Response Behaviour

All responses are `Content-Type: application/json`.

| Scenario | Status |
|----------|--------|
| Route matched, handler returns a value | **200 OK** |
| OPTIONS preflight | **204 No Content** |
| Auth required, token missing or wrong | **401 Unauthorized** |
| No route matched | **404 Not Found** |
| Body exceeds size limit | **413 Payload Too Large** |

The handler's return value is passed directly to `json_encode`. Handlers are responsible for returning JSON-serializable data. Use PHP's `JSON_UNESCAPED_UNICODE` and `JSON_UNESCAPED_SLASHES` flags are applied automatically.

---

## Security Features

### Timing-safe token comparison

`hash_equals()` is used instead of `===` so that the comparison takes the same amount of time regardless of the point where the strings diverge. This prevents an attacker from deducing the token character-by-character by measuring response times.

### Payload size limit

`php://input` is read with a hard cap (`setMaxInputSize`). Oversized payloads are rejected before any decoding or handler invocation, mitigating memory exhaustion and slow-POST attacks.

### Hardening headers (every response)

| Header | Value | Effect |
|--------|-------|--------|
| `X-Content-Type-Options` | `nosniff` | Prevents MIME-type sniffing in browsers. |
| `X-Frame-Options` | `DENY` | Blocks the response from being loaded in an iframe or frame. |
| `Referrer-Policy` | `no-referrer` | Omits the `Referer` header on all requests originating from API responses. |
| `Cache-Control` | `no-store` | Instructs browsers and proxies not to cache API responses. |

### Regex safety in route matching

Static path segments are escaped with `preg_quote()` before being compiled into the match regex. This prevents dots, parentheses, or other metacharacters in a route definition from altering the compiled pattern and potentially matching unintended paths.

### `getallheaders()` fallback

On CGI and FastCGI servers where `getallheaders()` is not available, headers are reconstructed from `$_SERVER`. The lookup is also case-insensitive, ensuring consistent behaviour across web server configurations.

### Empty token guard

If `setAuthToken` is never called (or is called with an empty string), `isAuthenticated()` returns `false` immediately, so routes marked `$requiresAuth = true` always respond with 401 rather than inadvertently allowing access.

---

## Best Practices

- **Use environment variables for secrets**: `setAuthToken(getenv('API_SECRET'))` — never commit a token to source control.
- **Validate dynamic segments inside handlers**: path params are raw URL strings; cast and validate them before querying a database or performing any operation.
- **Run behind HTTPS**: the handler does not enforce HTTPS. Configure TLS at the web server or load-balancer level.
- **Avoid wildcard CORS in production**: use an explicit origin allowlist when the API serves authenticated requests.
- **Return consistent shapes**: handlers should return arrays with predictable keys so consumers can rely on the response structure.
- **Check JSON input is present**: call `getInput()` and check for `null` in POST/PUT/PATCH handlers before accessing fields.

---

## HTTP Status Code Reference

| Code | Meaning | When sent |
|------|---------|-----------|
| 200 | OK | Route matched and handler returned successfully. |
| 204 | No Content | OPTIONS preflight (CORS). |
| 401 | Unauthorized | Auth-required route; token absent or incorrect. |
| 404 | Not Found | No registered route matched the request. |
| 413 | Payload Too Large | Request body exceeds `setMaxInputSize` limit. |

---

## Full Usage Example

```php
<?php

require_once 'Cherry_RESTful_API_Handler.php';

// 1. Initialize
CherryRESTfulAPI::init();

// 2. Configure
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));
CherryRESTfulAPI::setMaxInputSize(256_000);          // 250 KB
CherryRESTfulAPI::enableCORS('https://app.example.com');

// 3. Register routes
CherryRESTfulAPI::addRoute('GET', '/users', function (): array {
    return ['users' => []];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('GET', '/users/{id}', function (string $id): array {
    $id = (int) $id; // cast after receiving as string
    return ['id' => $id, 'name' => 'Alice'];
});

CherryRESTfulAPI::addRoute('POST', '/users', function (): array {
    $body = CherryRESTfulAPI::getInput();
    $name = $body['name'] ?? null;
    if ($name === null) {
        return ['error' => 'name is required'];
    }
    return ['created' => true, 'name' => $name];
});

CherryRESTfulAPI::addRoute('PUT', '/users/{id}', function (string $id): array {
    $body = CherryRESTfulAPI::getInput();
    return ['updated' => true, 'id' => (int) $id];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): array {
    return ['deleted' => true, 'id' => (int) $id];
}, requiresAuth: true);

// 4. Dispatch
CherryRESTfulAPI::processRequest();
```
