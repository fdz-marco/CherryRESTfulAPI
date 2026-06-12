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

CherryRESTfulAPI::init();
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET')); // load from env, never hard-code

CherryRESTfulAPI::addRoute('GET', '/users', function (): array {
    return ['users' => []];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('GET', '/users/{id}', function (string $id): array {
    return ['id' => $id];
});

CherryRESTfulAPI::addRoute('POST', '/users', function (): array {
    $body = CherryRESTfulAPI::getInput();
    return ['created' => true, 'name' => $body['name'] ?? null];
});

CherryRESTfulAPI::addRoute('PUT', '/users/{id}', function (string $id): array {
    return ['updated' => true, 'id' => $id];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): array {
    return ['deleted' => true, 'id' => $id];
}, requiresAuth: true);

CherryRESTfulAPI::processRequest();
```

For the full API reference, configuration options, security details, and best practices see **[DOCUMENTATION.md](DOCUMENTATION.md)**.

---

## Server Configuration

### Apache — root folder

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>
```

### Apache — sub-folder (e.g. `/api`)

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /api/
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^(.*)$ index.php [QSA,L]
</IfModule>
```

### Nginx — root folder

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/your/project;
    index index.php;

    location / {
        try_files $uri /index.php;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Nginx — sub-folder (e.g. `/api`)

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /path/to/your/project;
    index index.php;

    location /api/ {
        try_files $uri /api/index.php;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## License

MIT — see [LICENSE](LICENSE).
