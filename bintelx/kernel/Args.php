<?php # bintelx/kernel/Args.php
namespace bX;

class Args {
    use CoroutineAware;

    # Per-request state (coroutine-isolated via ctx())
    public array $opt = [];
    public array $input = [];

    public function __construct()
    {
        # No auto-parse — ctx() creates empty instances.
        # Call Args::parseRequest() explicitly in FPM bootstrap (api.php).
        # In Swoole, SuperGlobalHydrator::hydrateArgs() writes directly to ctx().
    }

    # Parsea input desde superglobales — SOLO para FPM/CLI.
    # En Swoole NO usar: php://input no existe en coroutines.
    # En Swoole el body viene de $request->rawContent() y se pasa
    # via SuperGlobalHydrator::hydrateArgs() que escribe directo a ctx().
    public static function parseRequest(): void
    {
        $method = self::determineInputMethod();

        switch ($method) {
            case 'CLI':
                if (($_SERVER['argc'] ?? 0) > 1) {
                    self::processCommandLineArguments($_SERVER['argv'] ?? []);
                } else {
                    self::processStdinData();
                }
                break;
            case 'GET':
                if (!empty($_GET)) self::populateData($_GET);
                break;
            case 'POST':
                if (!empty($_POST)) {
                    self::populateData($_POST);
                }
                self::processJsonInputStream('POST');
                break;
            case 'PUT':
            case 'PATCH':
            case 'DELETE':
                self::processJsonInputStream($method);
                break;
            case 'STDIN':
                self::processStdinData();
                break;
        }
    }

    private static function determineInputMethod(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'CLI';
        } elseif (!empty($_SERVER['REQUEST_METHOD'])) {
            return strtoupper($_SERVER['REQUEST_METHOD']);
        }
        return 'STDIN';
    }

    # Procesa php://input según Content-Type.
    # Solo consume JSON (application/json). Binarios (octet-stream) se dejan intactos.
    # TODO: soportar application/x-www-form-urlencoded aquí si se necesita
    # form-encoded en POST/PUT/PATCH. Hoy PHP lo parsea en $_POST automáticamente
    # (solo para POST), pero PUT/PATCH/DELETE con form-encoded NO se parsean.
    # Para soportarlo: añadir bloque con parse_str(file_get_contents('php://input'), $data)
    # cuando Content-Type sea application/x-www-form-urlencoded y método no sea POST.
    private static function processJsonInputStream(string $method): void
    {
        $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';

        # Binarios: no tocar php://input (handlers lo leen directamente)
        if (str_contains($contentType, 'application/octet-stream')) {
            return;
        }

        # JSON
        if (str_contains($contentType, 'application/json')) {
            $jsonInput = file_get_contents('php://input');
            if (!empty($jsonInput)) {
                $data = json_decode($jsonInput, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    self::populateData($data);
                }
            }
            return;
        }

        # Form-encoded en PUT/PATCH/DELETE (PHP solo auto-parsea POST en $_POST)
        if (str_contains($contentType, 'application/x-www-form-urlencoded') && $method !== 'POST') {
            $raw = file_get_contents('php://input');
            if (!empty($raw)) {
                parse_str($raw, $data);
                self::populateData($data);
            }
        }
    }

    private static function processCommandLineArguments(array $argv): void
    {
        $args = [];
        $forcePost = false;

        for ($i = 1; $i < count($argv); $i++) {
            $arg = $argv[$i];

            if (str_starts_with($arg, '--')) {
                $arg = substr($arg, 2);
                if (str_contains($arg, '=')) {
                    [$key, $value] = explode('=', $arg, 2);
                    $args[$key] = self::processArgumentValue($value);
                } else {
                    $args[$arg] = true;
                }
            } elseif (str_starts_with($arg, '-')) {
                $arg = substr($arg, 1);
                if (strlen($arg) > 1) {
                    for ($c = 0; $c < strlen($arg); $c++) {
                        $args[$arg[$c]] = true;
                    }
                } elseif (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
                    $args[$arg] = self::processArgumentValue($argv[++$i]);
                } else {
                    $args[$arg] = true;
                }
            } else {
                if (str_contains($arg, '=')) {
                    [$key, $value] = explode('=', $arg, 2);
                    $args[$key] = self::processArgumentValue($value);
                } else {
                    $args[$arg] = true;
                }
            }
            if ((isset($args['method']) && $args['method'] === 'POST') || (isset($args['POST']) && $args['POST'])) {
                $forcePost = true;
            }
        }

        self::populateData($args);
    }

    private static function processArgumentValue(string $value): mixed
    {
        if (str_starts_with($value, '{') || str_starts_with($value, '[')
            || str_starts_with($value, '"[') || str_starts_with($value, '"{')
        ) {
            $decodedValue = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decodedValue;
            }
        }
        return $value;
    }

    private static function processStdinData(): void
    {
        if (!defined('STDIN') || !is_resource(\STDIN)) {
            return;
        }

        $stdin = @stream_get_contents(\STDIN, -1, 0);
        if (!empty($stdin)) {
            $data = [];
            if (str_starts_with($stdin, '{') || str_starts_with($stdin, '[')) {
                $decodedValue = json_decode($stdin, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $data = $decodedValue;
                }
            } else {
                parse_str($stdin, $data);
            }

            self::populateData($data);
        }
    }

    /**
     * Populates ctx() with data
     */
    private static function populateData(array $data): void
    {
        self::ctx()->opt = array_merge(self::ctx()->opt, $data);
        self::ctx()->input = array_merge(self::ctx()->input, $data);
    }
}
