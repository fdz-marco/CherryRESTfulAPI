<?php

require_once '../Cherry_RESTful_API_Handler.php';

// Load env vars (requires vlucas/phpdotenv or server-level env config).
// See .env.example for setup options.
$secret       = getenv('API_SECRET')       ?: 'YOUR_SECRET_API_KEY';
$debug        = getenv('APP_DEBUG')        === 'true';
$basePath     = getenv('APP_BASE_PATH')    ?: '';
$maxInput     = (int) (getenv('APP_MAX_INPUT_SIZE') ?: 1_048_576);
$corsOrigins  = getenv('CORS_ORIGINS')     ?: '*';

// Configure
CherryRESTfulAPI::setBasePath($basePath);
CherryRESTfulAPI::init();

CherryRESTfulAPI::setAuthToken($secret);
CherryRESTfulAPI::setDebugMode($debug);
CherryRESTfulAPI::setMaxInputSize($maxInput);
CherryRESTfulAPI::enableCORS($corsOrigins === '*' ? '*' : explode(',', $corsOrigins));

// Routes
CherryRESTfulAPI::addRoute('GET', '/users', function (): array {
    $page = (int) CherryRESTfulAPI::getParam('page', 1);
    return ['message' => 'List of users', 'page' => $page];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('GET', '/users/{id}', function (string $id): array {
    return ['message' => 'User details', 'id' => $id];
});

CherryRESTfulAPI::addRoute('GET', '/users/{id}/{id2}', function (string $id, string $id2): array {
    return ['message' => 'User details', 'id' => $id, 'id2' => $id2];
});

CherryRESTfulAPI::addRoute('POST', '/users', function (): void {
    $body = CherryRESTfulAPI::getInput();
    if (empty($body['name'])) {
        CherryRESTfulAPI::respond(422, ['error' => 'name is required']);
    }
    // ... create user ...
    CherryRESTfulAPI::respond(201, ['message' => 'User created', 'name' => $body['name']]);
});

CherryRESTfulAPI::addRoute('PUT', '/users/{id}', function (string $id): array {
    return ['message' => 'User updated', 'id' => $id];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): void {
    // ... delete user ...
    CherryRESTfulAPI::respond(204); // No Content
}, requiresAuth: true);

// Dispatch
CherryRESTfulAPI::processRequest();
