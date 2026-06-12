<?php

declare(strict_types=1);

## ############################################################### ##
##  ----------------- Cherry RESTful API Handler ----------------  ##
##                                                                 ##
##  @package     Cherry_RESTful_API_Handler                        ##
##  @author      Marco Fernandez                                   ##
##  @link        marcofdz.com / glitcher.dev / inventtoo.com       ##
##  @link        https://github.com/fdz-marco                      ##
##  @version     0.2.0 (2026.06.12)                                ##
##  @license     https://opensource.org/licenses/MIT               ##
##  @copyright   2024-2026 marcofdz.com / glitcher.dev / inventtoo.com  ##
##                                                                 ##
## ############################################################### ##

class CherryRESTfulAPI {

    private static string $_requestMethod = '';
    private static array  $_endpoint      = [];
    private static ?array $_input         = null;
    private static array  $_routes        = [];

    // Auth
    private static string $_authToken     = '';

    // Input
    private static int    $_maxInputSize  = 1_048_576; // 1 MB default

    // CORS
    private static bool   $_corsEnabled   = false;
    private static array  $_corsOrigins   = [];

    // Routing
    private static string $_basePath      = '';

    // Error handling
    private static bool   $_debugMode     = false;

    /***
    =========================================================
    Initializing
    =========================================================
    ***/

    /**
     * Initialize the RESTful API Handler.
     *
     * Reads the HTTP method, parses the request URI into endpoint segments
     * (stripping the configured base path when set), and decodes the request
     * body. Must be called after setBasePath() and before addRoute() /
     * processRequest().
     */
    public static function init(): void {
        self::$_requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');

        // Strip the configured base path so routes are always relative.
        if (self::$_basePath !== '' && str_starts_with($path, self::$_basePath)) {
            $path = trim(substr($path, strlen(self::$_basePath)), '/');
        }

        self::$_endpoint = $path !== '' ? explode('/', $path) : [];
        self::$_input    = self::parseInput();
    }

    /**
     * Read and decode the request body.
     *
     * Enforces the configured maximum payload size (see setMaxInputSize()).
     * Falls back to $_POST when the body is absent. Returns null and does NOT
     * fall back to $_POST when the body is present but contains invalid JSON,
     * preventing silent data substitution. Sends 413 and terminates if the
     * payload exceeds the configured size limit.
     */
    private static function parseInput(): ?array {
        $raw = file_get_contents('php://input', false, null, 0, self::$_maxInputSize + 1);

        if ($raw === false || $raw === '') {
            return !empty($_POST) ? $_POST : null;
        }

        // Reject payloads that exceed the size limit before decoding.
        if (strlen($raw) > self::$_maxInputSize) {
            self::sendResponse(413, ['error' => 'Payload too large']);
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            // Body is present but not valid JSON — do not silently swap to $_POST.
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Return the decoded request body (JSON object or POST fields), or null
     * when the body is absent or could not be decoded.
     */
    public static function getInput(): ?array {
        return self::$_input;
    }

    /**
     * Return all query-string parameters ($_GET).
     */
    public static function getQueryParams(): array {
        return $_GET;
    }

    /**
     * Return a single query-string parameter by key, or $default when absent.
     *
     * @param mixed $default  Value returned when the key is not present.
     */
    public static function getParam(string $key, mixed $default = null): mixed {
        return $_GET[$key] ?? $default;
    }

    /**
     * Set the maximum accepted request body size in bytes.
     *
     * Requests with a body larger than this limit receive a 413 response.
     * Default: 1 048 576 bytes (1 MB).
     */
    public static function setMaxInputSize(int $bytes): void {
        self::$_maxInputSize = max(1, $bytes);
    }

    /***
    =========================================================
    Routing
    =========================================================
    ***/

    /**
     * Set a URL base path that is stripped from every request before matching.
     *
     * Use this when the API lives in a sub-directory, e.g. '/api' or '/v1'.
     * Must be called before init().
     */
    public static function setBasePath(string $basePath): void {
        self::$_basePath = trim($basePath, '/');
    }

    /**
     * Register a route.
     *
     * @param string   $request_method  HTTP verb (GET, POST, PUT, PATCH, DELETE, …).
     * @param string   $path            URI path; use {name} for dynamic segments, e.g. /users/{id}.
     * @param callable $handler         Invoked on match; receives dynamic segments as positional
     *                                  string arguments. May return any JSON-encodable value, or
     *                                  call CherryRESTfulAPI::respond() to send a custom status.
     * @param bool     $requiresAuth    When true, a valid Bearer token is required (see setAuthToken()).
     */
    public static function addRoute(
        string   $request_method,
        string   $path,
        callable $handler,
        bool     $requiresAuth = false
    ): void {
        self::$_routes[] = [
            'request_method' => strtoupper($request_method),
            'path'           => trim($path, '/'),
            'handler'        => $handler,
            'requiresAuth'   => $requiresAuth,
        ];
    }

    /**
     * Build a full-match regex from a route path.
     *
     * Each literal path segment is passed through preg_quote() so that dots,
     * parentheses, and other regex metacharacters in static path parts cannot
     * alter pattern behaviour. Each {param} placeholder is replaced by a
     * ([^/]+) capture group.
     */
    private static function buildRouteRegex(string $path): string {
        $parts = preg_split('/(\{[a-zA-Z0-9_]+\})/', $path, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regex = '';
        foreach ($parts as $part) {
            $regex .= preg_match('/^\{[a-zA-Z0-9_]+\}$/', $part)
                ? '([^/]+)'
                : preg_quote($part, '#');
        }
        return "#^$regex$#";
    }

    /***
    =========================================================
    Authentication
    =========================================================
    ***/

    /**
     * Set the Bearer token used to protect authenticated routes.
     *
     * Load the value from an environment variable or a secrets manager.
     * Never hard-code a real token in source control.
     *
     * Example: CherryRESTfulAPI::setAuthToken(getenv('API_SECRET'));
     */
    public static function setAuthToken(string $token): void {
        self::$_authToken = $token;
    }

    /**
     * Validate the Authorization header against the configured token.
     *
     * Uses hash_equals() for a constant-time comparison that prevents
     * timing-based side-channel attacks. Header lookup is case-insensitive
     * to work across different server environments. Returns false immediately
     * when no token has been configured.
     */
    private static function isAuthenticated(): bool {
        if (self::$_authToken === '') {
            return false;
        }
        $headers  = self::getAllRequestHeaders();
        // Normalise to avoid case-sensitivity differences across web servers.
        $received = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        return hash_equals('Bearer ' . self::$_authToken, $received);
    }

    /**
     * Return all HTTP request headers.
     *
     * Prefers getallheaders() (Apache / PHP-FPM with Apache). Falls back to
     * reconstructing headers from $_SERVER for CGI and FastCGI environments
     * where getallheaders() is not defined.
     */
    private static function getAllRequestHeaders(): array {
        if (function_exists('getallheaders')) {
            return getallheaders() ?: [];
        }
        // CGI / FastCGI fallback.
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace('_', '-', ucwords(strtolower(substr($key, 5)), '_'));
                $headers[$name] = (string) $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['Content-Type'] = (string) $value;
            } elseif ($key === 'CONTENT_LENGTH') {
                $headers['Content-Length'] = (string) $value;
            }
        }
        return $headers;
    }

    /***
    =========================================================
    CORS
    =========================================================
    ***/

    /**
     * Enable Cross-Origin Resource Sharing (CORS).
     *
     * Pass a specific origin string, an array of allowed origins, or '*' to
     * permit any origin. The wildcard ('*') is incompatible with credentialed
     * requests; prefer an explicit allowlist in production.
     *
     * Must be called before processRequest().
     *
     * @param string|array<string> $origins  Allowed origin(s). Default: '*'.
     */
    public static function enableCORS(string|array $origins = '*'): void {
        self::$_corsEnabled = true;
        self::$_corsOrigins = is_array($origins) ? $origins : [$origins];
    }

    /**
     * Emit CORS response headers when CORS is enabled.
     *
     * For a wildcard allowlist, Access-Control-Allow-Origin is set to '*'.
     * For an explicit allowlist, the request's Origin is reflected only when
     * it appears in the list, and a Vary: Origin header is added so shared
     * caches do not serve one origin's response to a different origin.
     */
    private static function sendCORSHeaders(): void {
        if (!self::$_corsEnabled) {
            return;
        }
        $requestOrigin = $_SERVER['HTTP_ORIGIN'] ?? '';

        if (in_array('*', self::$_corsOrigins, true)) {
            header('Access-Control-Allow-Origin: *');
        } elseif ($requestOrigin !== '' && in_array($requestOrigin, self::$_corsOrigins, true)) {
            header('Access-Control-Allow-Origin: ' . $requestOrigin);
            header('Vary: Origin');
        }

        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept');
        header('Access-Control-Max-Age: 86400');
    }

    /***
    =========================================================
    Error Handling
    =========================================================
    ***/

    /**
     * Enable or disable debug mode.
     *
     * In debug mode, unhandled exceptions thrown by route handlers are
     * serialized into the 500 response (message, file, line). In production,
     * only a generic "Internal server error" message is returned.
     *
     * Never enable debug mode in a public-facing environment.
     */
    public static function setDebugMode(bool $debug): void {
        self::$_debugMode = $debug;
    }

    /***
    =========================================================
    Process Request / Response
    =========================================================
    ***/

    /**
     * Dispatch the incoming request to the first matching registered route.
     *
     * Emits security and CORS headers on every response. Handles OPTIONS
     * preflight with 204 and HEAD requests by matching against GET routes
     * without invoking the handler or sending a body.
     *
     * Route matching respects registration order; the first match wins.
     * When a path matches but no registered method does, a 405 is returned
     * with an Allow header listing the supported methods. Unmatched paths
     * receive 404.
     *
     * Exceptions thrown by handlers are caught: in debug mode the exception
     * detail is included in the 500 response; in production only a generic
     * message is returned.
     */
    public static function processRequest(): void {
        self::sendSecurityHeaders();
        self::sendCORSHeaders();

        // Short-circuit CORS preflight requests.
        if (self::$_requestMethod === 'OPTIONS') {
            self::sendResponse(204, null);
        }

        // HEAD is handled by matching GET routes and suppressing the body.
        $isHead        = self::$_requestMethod === 'HEAD';
        $effectiveMethod = $isHead ? 'GET' : self::$_requestMethod;

        $endpointPath    = implode('/', self::$_endpoint);
        $pathMatchedMethods = [];

        foreach (self::$_routes as $route) {
            $pathMatches = preg_match(
                self::buildRouteRegex($route['path']),
                $endpointPath,
                $matches
            );

            if (!$pathMatches) {
                continue;
            }

            // Track which methods are registered for this path (for 405).
            $pathMatchedMethods[] = $route['request_method'];

            if ($route['request_method'] !== $effectiveMethod) {
                continue;
            }

            array_shift($matches); // Remove the full-match entry; keep capture groups only.

            if ($route['requiresAuth'] && !self::isAuthenticated()) {
                self::sendResponse(401, ['error' => 'Unauthorized']);
            }

            // HEAD: path exists — return 200 with no body, no handler call.
            if ($isHead) {
                self::sendResponse(200, null);
            }

            try {
                $result = call_user_func_array($route['handler'], $matches);
            } catch (Throwable $e) {
                // Never let an unhandled exception expose a raw PHP error to the client.
                self::sendResponse(500, self::$_debugMode
                    ? ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]
                    : ['error' => 'Internal server error']
                );
            }

            self::sendResponse(200, $result);
        }

        // Path was recognised but the method is not registered for it.
        if (!empty($pathMatchedMethods)) {
            $allowed = array_unique($pathMatchedMethods);
            // HEAD is implicitly supported wherever GET is.
            if (in_array('GET', $allowed, true)) {
                $allowed[] = 'HEAD';
            }
            sort($allowed);
            header('Allow: ' . implode(', ', $allowed));
            self::sendResponse(405, ['error' => 'Method not allowed']);
        }

        self::sendResponse(404, ['error' => 'Not found']);
    }

    /**
     * Send a response with a custom HTTP status code from inside a handler.
     *
     * Calling this from a handler bypasses the default 200 status that
     * processRequest() would otherwise use. Terminates execution.
     *
     * Example:
     *   CherryRESTfulAPI::addRoute('POST', '/users', function (): void {
     *       // ... create user ...
     *       CherryRESTfulAPI::respond(201, ['id' => 42]);
     *   });
     *
     * @param int   $statusCode  HTTP status code to send.
     * @param mixed $data        JSON-encodable response body, or null for an empty body.
     */
    public static function respond(int $statusCode, mixed $data = null): never {
        self::sendResponse($statusCode, $data);
    }

    /**
     * Emit hardening headers included on every API response.
     *
     * - X-Content-Type-Options: nosniff — prevents MIME-type sniffing.
     * - X-Frame-Options: DENY       — blocks embedding in iframes.
     * - Referrer-Policy: no-referrer — omits Referer on cross-origin requests.
     * - Cache-Control: no-store      — prevents caching of API responses.
     */
    private static function sendSecurityHeaders(): void {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
    }

    /**
     * Serialize $response as JSON, set the HTTP status code, and terminate.
     *
     * Passing null for $response emits an empty body (suitable for 204 No
     * Content, 304 Not Modified, and HEAD responses).
     *
     * @param int   $statusCode  HTTP status code to send.
     * @param mixed $response    Value to JSON-encode, or null for an empty body.
     */
    private static function sendResponse(int $statusCode, mixed $response): never {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        if ($response !== null) {
            echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        exit;
    }
}
