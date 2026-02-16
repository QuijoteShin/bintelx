<?php # bintelx/kernel/SuperGlobalHydrator.php

namespace bX;

# Hidratador de superglobales para contextos aislados (Swoole Workers, CLI, Tests)
# Permite simular entorno HTTP completo sin contaminar el estado global
class SuperGlobalHydrator
{
    /**
     * Hidrata $_SERVER, $_GET, $_POST, $_COOKIE según el request
     */
    public static function hydrate(array $request): void
    {
        $method = strtoupper($request['method'] ?? 'GET');
        $uri = $request['uri'] ?? '/';
        $headers = $request['headers'] ?? [];
        $body = $request['body'] ?? [];
        $query = $request['query'] ?? [];
        $cookies = $request['cookies'] ?? [];
        $remoteAddr = $request['remote_addr'] ?? '127.0.0.1';

        # $_SERVER base
        $_SERVER = [
            'REQUEST_METHOD' => $method,
            'REQUEST_URI' => $uri,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => 'localhost',
            'SERVER_PORT' => 80,
            'REMOTE_ADDR' => $remoteAddr,
            'REMOTE_PORT' => rand(10000, 65535),
            'REQUEST_TIME' => time(),
            'REQUEST_TIME_FLOAT' => microtime(true),
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_USER_TIMEZONE' => Config::get('DEFAULT_TIMEZONE', 'America/Santiago'),
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => __DIR__ . '/../../public/index.php',
        ];

        # Inyectar headers HTTP
        foreach ($headers as $key => $value) {
            $headerKey = 'HTTP_' . strtoupper(str_replace('-', '_', $key));
            $_SERVER[$headerKey] = $value;
        }

        # Content-Length para POST/PUT/PATCH
        if (in_array($method, ['POST', 'PUT', 'PATCH']) && !empty($body)) {
            $_SERVER['CONTENT_LENGTH'] = strlen(json_encode($body));
        }

        # $_POST para métodos que envían body
        $_POST = in_array($method, ['POST', 'PUT', 'PATCH']) ? $body : [];

        # $_GET siempre refleja query string (estándar PHP — independiente del método)
        $_GET = $query;

        # $_COOKIE
        $_COOKIE = $cookies;

        # $_REQUEST (merge)
        $_REQUEST = array_merge($_GET, $_POST, $_COOKIE);

        # $_FILES (vacío por ahora)
        $_FILES = [];
    }

    /**
     * Hidrata Args (clase custom de Bintelx)
     */
    public static function hydrateArgs(string $method, array $body, array $query): void
    {
        # Reset Args context for this request
        Args::resetCtx();

        # Poblar según método HTTP
        $inputData = ($method === 'GET') ? $query : $body;
        Args::ctx()->opt = $inputData;
        Args::ctx()->input = $inputData;
    }

    /**
     * Crea snapshot de superglobales para restaurar después
     */
    public static function snapshot(): array
    {
        return [
            'SERVER' => $_SERVER ?? [],
            'GET' => $_GET ?? [],
            'POST' => $_POST ?? [],
            'COOKIE' => $_COOKIE ?? [],
            'FILES' => $_FILES ?? [],
            'REQUEST' => $_REQUEST ?? [],
        ];
    }

    /**
     * Restaura superglobales desde snapshot
     */
    public static function restore(array $snapshot): void
    {
        $_SERVER = $snapshot['SERVER'];
        $_GET = $snapshot['GET'];
        $_POST = $snapshot['POST'];
        $_COOKIE = $snapshot['COOKIE'];
        $_FILES = $snapshot['FILES'];
        $_REQUEST = $snapshot['REQUEST'];
    }

    /**
     * Limpia todas las superglobales
     */
    public static function clear(): void
    {
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_FILES = [];
        $_REQUEST = [];
    }
}
