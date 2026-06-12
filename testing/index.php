<?php

require_once '../Cherry_RESTful_API_Handler.php';

// Initialize
CherryRESTfulAPI::init();
CherryRESTfulAPI::setAuthToken(getenv('API_SECRET') ?: 'YOUR_SECRET_API_KEY');

// Routes
CherryRESTfulAPI::addRoute('GET', '/users', function (): array {
    return ['message' => 'List of users'];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('GET', '/users/{id}', function (string $id): array {
    return ['message' => 'User details', 'id' => $id];
});

CherryRESTfulAPI::addRoute('GET', '/users/{id}/{id2}', function (string $id, string $id2): array {
    return ['message' => 'User details', 'id' => $id, 'id2' => $id2];
});

CherryRESTfulAPI::addRoute('POST', '/users', function (): array {
    $body = CherryRESTfulAPI::getInput();
    return ['message' => 'User created', 'data' => $body];
});

CherryRESTfulAPI::addRoute('PUT', '/users/{id}', function (string $id): array {
    return ['message' => 'User updated', 'id' => $id];
}, requiresAuth: true);

CherryRESTfulAPI::addRoute('DELETE', '/users/{id}', function (string $id): array {
    return ['message' => 'User deleted', 'id' => $id];
}, requiresAuth: true);

// Dispatch
CherryRESTfulAPI::processRequest();
