### Welcome to Cherry RESTful API Handler

# What is this?
![Cherry RESTful API Handler](https://raw.github.com/fdz-marco/CherryRESTfulAPI/master/design/CherryRESTfulAPIHandler_mini.png "Cherry RESTful API")

*Cherry RESTful API Handler* is a lightweight PHP class for building RESTful APIs with routing, Bearer token authentication, CORS support, and security hardening built in.

**Requires PHP 8.1+** · MIT License

---

## Quick Start

```php
<?php

require_once 'Cherry_RESTful_API_Handler.php';

// 1. Configure (setBasePath must come before init)
CherryRESTfulAPI::setBasePath('');                          // set '/api' if in a subfolder
CherryRESTfulAPI::init();
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));       // load from env, never hard-code
CherryRESTfulAPI::setDebugMode(getenv('APP_DEBUG') === 'true');
CherryRESTfulAPI::enableCORS('https://app.example.com');   // or '*' for any origin

// 2. Register routes
CherryRESTfulAPI::addRoute('GET', '/users', function (): array {
    $page = (int) CherryRESTfulAPI::getParam('page', 1);   // ?page=2
    return ['users' => [], 'page' => $page];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('GET', '/users/{id}', function (string $id): array {
    return ['id' => $id];
});

CherryRESTfulAPI::addRoute('POST', '/users', function (): void {
    $body = CherryRESTfulAPI::getInput();
    if (empty($body['name'])) {
        CherryRESTfulAPI::respond(422, ['error' => 'name is required']);
    }
    CherryRESTfulAPI::respond(201, ['created' => true]);    // custom status code
});

CherryRESTfulAPI::addRoute('PUT', '/users/{id}', function (string $id): array {
    return ['updated' => true, 'id' => $id];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): void {
    CherryRESTfulAPI::respond(204);                         // 204 No Content
}, requiresAuth: true);

// 3. Dispatch
CherryRESTfulAPI::processRequest();
```

For the full API reference, all configuration options, security details, and best practices see **[DOCUMENTATION.md](DOCUMENTATION.md)**.

---

## Environment Setup

Copy `.env.example` to `.env` and fill in the values. PHP does not load `.env` files natively — see the comments inside `.env.example` for the three supported approaches (phpdotenv, Apache `SetEnv`, Nginx `fastcgi_param`).

```bash
cp .env.example .env
```

---

## Server Configuration

Production-ready config files are in [server-configs/](server-configs/).

| File | Use |
|------|-----|
| [server-configs/apache/.htaccess](server-configs/apache/.htaccess) | Apache — API at root. Copy to your project root. |
| [server-configs/apache/subfolder.htaccess](server-configs/apache/subfolder.htaccess) | Apache — API in a subfolder (e.g. `/api/`). Rename to `.htaccess` and copy to your subfolder. |
| [server-configs/apache/virtualhost.conf](server-configs/apache/virtualhost.conf) | Apache — full virtual host with SSL, PHP-FPM, and hardening headers. |
| [server-configs/nginx/site.conf](server-configs/nginx/site.conf) | Nginx — API at root. Full server block with SSL and PHP-FPM. |
| [server-configs/nginx/site-subfolder.conf](server-configs/nginx/site-subfolder.conf) | Nginx — API in a subfolder. Pair with `setBasePath()`. |

All configs include:
- HTTP → HTTPS redirect
- TLS 1.2 / 1.3 only
- Block access to `.env`, `.git`, `composer.json`
- Security headers (`HSTS`, `X-Frame-Options`, etc.)

---

## License

MIT — see [LICENSE](LICENSE).
