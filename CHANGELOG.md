# Changelog

All notable changes to this project will be documented in this file.

Format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).
Versioning follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [0.2.0] — 2026-06-12

### Added
- `respond(int $statusCode, mixed $data = null): never` — public method for handlers to send any HTTP status code (e.g. 201, 204, 422) instead of always returning 200.
- `getQueryParams(): array` — exposes `$_GET` query-string parameters to handlers.
- `getParam(string $key, mixed $default): mixed` — retrieves a single query-string parameter with an optional default.
- `setBasePath(string $path)` — strips a URL prefix before route matching; required when the API lives in a sub-directory (e.g. `/api`). Must be called before `init()`.
- `setDebugMode(bool $debug)` — when `true`, unhandled exceptions include message, file, and line in the 500 response body. Always `false` in production.
- **HEAD method support** — HEAD requests are matched against GET routes automatically; the handler is not invoked and no body is sent, satisfying the HTTP specification.
- **405 Method Not Allowed** — when a path matches but no route for the requested method is registered, a 405 is returned with an `Allow` header listing valid methods (HEAD is included automatically when GET is registered). Previously these fell through to 404.
- **Exception containment** — `processRequest()` wraps all handler calls in `try / catch (Throwable)`. Uncaught exceptions now return a controlled 500 JSON response instead of leaking a raw PHP error or stack trace to the client.
- `server-configs/apache/.htaccess` — production-ready Apache config for root placement.
- `server-configs/apache/subfolder.htaccess` — Apache config for sub-directory placement; rename to `.htaccess` and copy to your API folder.
- `server-configs/apache/virtualhost.conf` — full Apache virtual host example with TLS, PHP-FPM, HTTP→HTTPS redirect, and hardening headers.
- `server-configs/nginx/site.conf` — full Nginx server block for root placement with TLS 1.2/1.3, OCSP stapling, and hardening headers.
- `server-configs/nginx/site-subfolder.conf` — Nginx config for API in a sub-path; pairs with `setBasePath()`.
- `.env.example` — documents all supported environment variables with instructions for Apache `SetEnv`, Nginx `fastcgi_param`, and `vlucas/phpdotenv`.

### Changed
- `testing/index.php` updated to demonstrate `respond()`, `getInput()`, `getParam()`, and env-driven configuration.
- `DOCUMENTATION.md` expanded with new sections: Initialization Order, Error Handling, full status code reference, and updated API table.
- `README.md` updated with server-configs table and `.env.example` setup instructions.

---

## [0.1.0] — 2026-06-11

### Added
- `declare(strict_types=1)` — enables strict type checking across the entire class.
- Full type declarations on all properties and method signatures (PHP 8.1+).
- `getInput(): ?array` — public accessor for the decoded request body.
- `setMaxInputSize(int $bytes)` — caps `php://input` reads to prevent memory exhaustion and slow-POST attacks. Default: 1 MB. Exceeding the limit returns 413.
- `enableCORS(string|array $origins)` — enables CORS support with configurable origin allowlist.
- `sendCORSHeaders()` — emits `Access-Control-*` headers; reflects Origin with `Vary: Origin` when using an explicit allowlist.
- `sendSecurityHeaders()` — emits `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, and `Cache-Control: no-store` on every response.
- OPTIONS preflight handling — `processRequest()` short-circuits with **204 No Content** for OPTIONS requests.
- `getAllRequestHeaders()` — internal helper with a `$_SERVER` fallback for CGI / FastCGI environments where `getallheaders()` is unavailable.
- `sendResponse()` typed as `never` and updated to pass `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.
- `DOCUMENTATION.md` — full API reference, security feature explanations, best practices, and a complete usage example.

### Changed
- `buildRouteRegex()` rewritten to use `preg_split` + `preg_quote` on literal path segments, preventing regex metacharacters (`.`, `+`, `(`, etc.) in static path parts from altering pattern behaviour.
- `parseInput()` extracted from `init()`: now enforces the size limit, checks `json_last_error()` explicitly, and no longer falls back to `$_POST` when the body is present but contains invalid JSON.
- `init()` now guards against a missing or empty `REQUEST_URI` and `REQUEST_METHOD`.
- `README.md` condensed to a quick-start reference; full documentation moved to `DOCUMENTATION.md`.

### Fixed
- **Timing attack on token comparison** — replaced `===` with `hash_equals()` for constant-time Bearer token validation.
- **Case-sensitive header lookup** — `isAuthenticated()` now checks both `Authorization` and `authorization` to work across all web server configurations.
- **`getallheaders()` crash on CGI/FastCGI** — added `$_SERVER` fallback so the class works on Nginx + FastCGI without modification.

### Removed
- Unused `$_url` static property.
- Hard-coded default token `"YOUR_SECRET_API_KEY"` — `$_authToken` now defaults to `''`; an empty token causes auth-required routes to always return 401.

### Security
- Timing-safe token comparison via `hash_equals()`.
- Payload size limit preventing unbounded memory reads from `php://input`.
- Security hardening headers on every response.
- Regex injection prevention in route pattern compilation.
- Empty-token guard so misconfigured deployments fail closed (401) rather than open.

---

## [0.0.2] — 2024-12-01

Initial public release.

### Features
- Static class with zero dependencies.
- `init()` — reads request method, parses URI, decodes JSON body (falling back to `$_POST`).
- `addRoute(method, path, handler, requiresAuth)` — registers routes with optional `{param}` placeholders.
- `setAuthToken(token)` — sets the Bearer token for protected routes.
- `isAuthenticated()` — checks the `Authorization: Bearer` header.
- `processRequest()` — matches request to a registered route and invokes the handler.
- `sendResponse(statusCode, data)` — JSON-encodes the response and exits.
- Routes return **200** on success, **401** on auth failure, **404** when no route matches.

[0.2.0]: https://github.com/fdz-marco/CherryRESTfulAPI/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/fdz-marco/CherryRESTfulAPI/compare/v0.0.2...v0.1.0
[0.0.2]: https://github.com/fdz-marco/CherryRESTfulAPI/releases/tag/v0.0.2
