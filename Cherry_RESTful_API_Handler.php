<?php

declare(strict_types=1);

## ############################################################### ##
##  ----------------- Cherry RESTful API Handler ----------------  ##
##                                                                 ##
##  @package     Cherry_RESTful_API_Handler                        ##
##  @author      Marco Fernandez                                   ##
##  @link        marcofdz.com / glitcher.dev / inventtoo.com       ##
##  @link        https://github.com/fdz-marco                      ##
##  @version     0.1.0 (2026.06.11)                                ##
##  @license     https://opensource.org/licenses/MIT               ##
##  @copyright   2024-2026 marcofdz.com / glitcher.dev / inventtoo.com  ##
##                                                                 ##
## ############################################################### ##

class CherryRESTfulAPI {

    private static string $_requestMethod = '';
    private static array  $_endpoint      = [];
    private static ?array $_input         = null;
    private static array  $_routes        = [];
    private static string $_authToken     = '';
    private static int    $_maxInputSize  = 1_048_576; // 1 MB default
    private static bool   $_corsEnabled   = false;
    private static array  $_corsOrigins   = [];

    /***
    =========================================================
    Initializing
    =========================================================
    ***/

    /**
     * Initialize the RESTful API Handler.
     *
     * Reads the HTTP method, parses the request URI into endpoint segments,
     * and decodes the request body. Must be called before addRoute() and
     * processRequest().
     */
    public static function init(): void {
        self::$_requestMethod = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
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
     * payload exceeds the configured limit.
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
     * Register a route.
     *
     * @param string   $request_method  HTTP verb (GET, POST, PUT, PATCH, DELETE, …).
     * @param string   $path            URI path; use {name} for dynamic segments, e.g. /users/{id}.
     * @param callable $handler         Invoked on match; receives dynamic segments as positional arguments.
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
        // Normalise to avoid case sensitivity differences across web servers.
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
     * caches do not serve the wrong origin's response to another client.
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
    Process Request / Response
    =========================================================
    ***/

    /**
     * Dispatch the incoming request to the first matching registered route.
     *
     * Emits security and CORS headers on every response. Handles OPTIONS
     * preflight with 204. Iterates registered routes; on a match the handler
     * is called with URL path params as positional arguments and its return
     * value is JSON-encoded with status 200. An unmatched request gets 404.
     */
    public static function processRequest(): void {
        self::sendSecurityHeaders();
        self::sendCORSHeaders();

        // Short-circuit CORS preflight requests.
        if (self::$_requestMethod === 'OPTIONS') {
            self::sendResponse(204, null);
        }

        $endpointPath = implode('/', self::$_endpoint);

        foreach (self::$_routes as $route) {
            if ($route['request_method'] !== self::$_requestMethod) {
                continue;
            }
            if (!preg_match(self::buildRouteRegex($route['path']), $endpointPath, $matches)) {
                continue;
            }
            array_shift($matches); // Remove the full-match entry; keep capture groups only.

            if ($route['requiresAuth'] && !self::isAuthenticated()) {
                self::sendResponse(401, ['error' => 'Unauthorized']);
            }

            $result = call_user_func_array($route['handler'], $matches);
            self::sendResponse(200, $result);
        }

        self::sendResponse(404, ['error' => 'Not found']);
    }

    /**
     * Emit security hardening headers included on every API response.
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
     * Passing null for $response emits an empty body, which is appropriate for
     * responses such as 204 No Content or 304 Not Modified.
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
