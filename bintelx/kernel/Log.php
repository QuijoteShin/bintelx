<?php # bintelx/kernel/Log.php

namespace bX;

class Log {
  # Propiedades estáticas configurables (inicializadas desde .env)
  public static bool $logToUser = false;
  public static bool $logToCli = true;
  public static bool $logToStdout = false;       # Async logging para Swoole/Docker (sin LOCK_EX)
  public static string $logLevel = 'ERROR';      # Nivel para archivo de log
  public static string $logLevelCli = 'DEBUG';   # Nivel para stdout (independiente)
  public static string $logLevelStdout = 'INFO'; # Nivel para stdout async

  private static bool $initialized = false;

  private static array $logLevels = [
      'DEBUG' => 1,
      'INFO' => 2,
      'WARNING' => 3,
      'ERROR' => 4,
      'NONE' => 5
  ];

  # Inicializa propiedades desde Config (.env)
  private static function init(): void {
    if (self::$initialized) return;

    self::$logToUser = Config::getBool('LOG_TO_USER', false);
    self::$logToCli = Config::getBool('LOG_TO_CLI', true);
    self::$logToStdout = Config::getBool('LOG_TO_STDOUT', false); # Async para Swoole/Docker
    self::$logLevel = strtoupper(Config::get('LOG_LEVEL', 'ERROR'));
    self::$logLevelCli = strtoupper(Config::get('LOG_LEVEL_CLI', 'DEBUG'));
    self::$logLevelStdout = strtoupper(Config::get('LOG_LEVEL_STDOUT', 'INFO'));

    self::$initialized = true;
  }

  private static function shouldLog(string $level): bool {
    self::init();

    $configLevelNumeric = self::$logLevels[self::$logLevel] ?? self::$logLevels['ERROR'];
    $messageLevelNumeric = self::$logLevels[strtoupper($level)] ?? self::$logLevels['ERROR'];
    return $messageLevelNumeric >= $configLevelNumeric;
  }

  private static function writeLog(string $level, string $message, array $context = []) {
    self::init();

    # Verificar si debe guardarse en archivo
    $shouldLogToFile = self::shouldLog($level);

    # Verificar si debe mostrarse en CLI (independiente)
    $configLevelCliNumeric = self::$logLevels[self::$logLevelCli] ?? self::$logLevels['DEBUG'];
    $messageLevelNumeric = self::$logLevels[strtoupper($level)] ?? self::$logLevels['ERROR'];
    $shouldLogToCli = $messageLevelNumeric >= $configLevelCliNumeric;

    # Si no se debe ni guardar ni mostrar, salir
    if (!$shouldLogToFile && !$shouldLogToCli) {
      return;
    }

    // Asegurarse de que WarmUp::$BINTELX_HOME esté inicializado
    $logPath = (isset(\bX\WarmUp::$BINTELX_HOME) && !empty(\bX\WarmUp::$BINTELX_HOME))
        ? \bX\WarmUp::$BINTELX_HOME . "../log/"
        : __DIR__ . "/../../log/"; // Fallback si WarmUp no está completamente listo

    // Crear directorio si no existe
    if (!is_dir($logPath)) {
      mkdir($logPath, 0775, true);
    }

    $timestamp = date("Y-m-d H:i:s.u P"); // Incluir microsegundos y timezone
    $fileDateSuffix = date("Y-m"); // Archivo de log mensual

    // Información del usuario y solicitud (puede ser diferente o no estar disponible en CLI)
    $userId = (class_exists('\bX\Profile') && isset(\bX\Profile::$account_id) && \bX\Profile::$account_id > 0)
        ? \bX\Profile::$account_id
        : ((isset($_SERVER['REMOTE_USER']) ? $_SERVER['REMOTE_USER'] : (php_sapi_name() === 'cli' ? 'CLI' : 'ANONYMOUS')));

    $requestUri = (isset($_SERVER['REQUEST_METHOD']) && isset($_SERVER['REQUEST_URI']))
        ? $_SERVER['REQUEST_METHOD'] . ' ' . $_SERVER['REQUEST_URI']
        : (php_sapi_name() === 'cli' && isset($_SERVER['argv']) ? 'CLI: ' . implode(' ', $_SERVER['argv']) : 'N/A');

    $logEntry = sprintf("[%s] [%s] [User:%s] [URI:%s] %s",
        $timestamp,
        strtoupper($level),
        $userId,
        $requestUri,
        $message
    );

    if (!empty($context)) {
      // Usar json_encode con flags para mejor legibilidad y manejo de errores
      $contextString = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
      if (json_last_error() !== JSON_ERROR_NONE) {
        $contextString = "Error encoding context: " . json_last_error_msg() . ". Raw: " . print_r($context, true);
      }
      $logEntry .= "\nContext: " . $contextString;
    }

    $logEntry .= "\n"; // Nueva línea al final de cada entrada

    // Nombre de archivo de log genérico por mes y nivel (opcional)
    // O puedes tener un solo archivo y filtrar por nivel al revisarlo.
    // Para simplicidad, usaremos un archivo por mes.
    # Escribir en archivo SOLO si pasa el filtro LOG_LEVEL
    if ($shouldLogToFile) {
      $logFile = $logPath . "bintelx_" . $fileDateSuffix . ".log";
      $logSuccess = file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);

      if (!$logSuccess && !headers_sent()) {
        error_log("CRITICAL: Failed to write to BintelX log file: {$logFile}. Log Entry: {$logEntry}");
      }
    }

    # Salida a CLI (independiente de LOG_LEVEL para archivo)
    $isCli = php_sapi_name() === 'cli';
    $isHttpRequest = isset($_SERVER['REQUEST_METHOD']) || isset($_SERVER['HTTP_HOST']);
    $stdoutAvailable = defined('STDOUT') && is_resource(\STDOUT);
    $isTty = $stdoutAvailable && function_exists('posix_isatty') && @posix_isatty(\STDOUT);

    if (self::$logToCli && $shouldLogToCli && $isCli && !$isHttpRequest && $isTty) {
      $colorCodes = [
        'DEBUG' => "\033[36m",   # Cyan
        'INFO' => "\033[32m",    # Green
        'WARNING' => "\033[33m", # Yellow
        'ERROR' => "\033[31m",   # Red
        'RESET' => "\033[0m"
      ];
      $color = $colorCodes[strtoupper($level)] ?? '';
      $reset = $colorCodes['RESET'];
      echo "{$color}{$logEntry}{$reset}";
    }

    # Async logging via STDOUT (Swoole/Docker/K8s) - NO LOCK_EX
    # Usa fwrite(STDOUT) que es no-bloqueante y delegado al supervisor
    if (self::$logToStdout) {
      $configLevelStdoutNumeric = self::$logLevels[self::$logLevelStdout] ?? self::$logLevels['INFO'];
      if ($messageLevelNumeric >= $configLevelStdoutNumeric) {
        # Formato JSON para parseo estructurado (ELK, Datadog, etc)
        $jsonEntry = json_encode([
          'timestamp' => date('c'),
          'level' => strtoupper($level),
          'message' => $message,
          'context' => $context ?: null,
          'user_id' => $userId,
          'uri' => $requestUri
        ], JSON_UNESCAPED_SLASHES);

        # fwrite a STDOUT es no-bloqueante (sin LOCK_EX)
        if ($stdoutAvailable) {
          fwrite(\STDOUT, $jsonEntry . "\n");
        } else {
          # Fallback: error_log delega al servidor web
          error_log($jsonEntry);
        }
      }
    }

    # Log específico para el usuario si está habilitado y es un error o warning
    if (self::$logToUser && ($level === 'ERROR' || $level === 'WARNING')) {
      $userLogFile = $logPath . "user_specific_" . $fileDateSuffix . "_user-" . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$userId) . ".log";
      file_put_contents($userLogFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
  }

  public static function logError(string $message, array $context = []) {
    // El backtrace ya se pasa como parte del $context si es necesario desde el errorHandler
    self::writeLog('ERROR', $message, $context);
  }

  public static function logWarning(string $message, array $context = []) {
    self::writeLog('WARNING', $message, $context);
  }

  public static function logInfo(string $message, array $context = []) {
    self::writeLog('INFO', $message, $context);
  }

  public static function logDebug(string $message, array $context = []) {
    self::writeLog('DEBUG', $message, $context);
  }
}

// La función errorHandler y formatBacktrace pueden permanecer fuera de la clase si se usan globalmente,
// o podrían ser métodos estáticos privados/protegidos dentro de la clase Log si se prefiere.
// Por ahora, las mantenemos como están en tu código original.

/**
 * Manejador de errores global para capturar errores de PHP y registrarlos.
 */
function errorHandler(int $errno, string $errstr, string $errfile, int $errline): bool {
  // No registrar errores suprimidos con @
  if (!(error_reporting() & $errno)) {
    return false;
  }

  // Convertir el nivel de error de PHP a un nivel de log
  $errorLevelStr = 'ERROR'; // Default
  switch ($errno) {
    case E_USER_ERROR:
      $errorLevelStr = 'INFO'; // O 'DEBUG' si quieres ser muy verboso
      break;
    case E_RECOVERABLE_ERROR:
      $errorLevelStr = 'ERROR';
      break;
    case E_USER_WARNING:
    case E_WARNING:
      $errorLevelStr = 'WARNING';
      break;
    case E_USER_NOTICE:
    case E_NOTICE:
    case E_DEPRECATED:
    case E_USER_DEPRECATED:
      $errorLevelStr = 'WARNING'; // O 'INFO'
      break;
    default:
      $errorLevelStr = 'ERROR'; // Para otros errores no especificados
      break;
  }

  $message = sprintf("%s: %s in %s on line %d",
      phpErrorConstantToString($errno), // Función helper para nombre del error
      $errstr,
      $errfile,
      $errline
  );

  // Obtener un backtrace limitado para no sobrecargar los logs
  $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10); // Limitar a 10 niveles, sin args

  // Decidir qué método de Log llamar basado en $errorLevelStr
  switch (strtoupper($errorLevelStr)) {
    case 'ERROR':
      Log::logError($message, ['php_error_details' => ['errno' => $errno, 'file' => $errfile, 'line' => $errline], 'backtrace_short' => formatBacktrace($backtrace, true)]);
      break;
    case 'WARNING':
      Log::logWarning($message, ['php_error_details' => ['errno' => $errno, 'file' => $errfile, 'line' => $errline], 'backtrace_short' => formatBacktrace($backtrace, true)]);
      break;
    case 'INFO':
      Log::logInfo($message, ['php_error_details' => ['errno' => $errno, 'file' => $errfile, 'line' => $errline]]);
      break;
    // No loguear DEBUG desde el manejador de errores de PHP, usualmente es para notices
  }

  /* No ejecutar el manejador de errores interno de PHP si no es un error fatal */
  /* Para errores fatales, PHP se detendrá de todas formas después de esto. */
  /* Para E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING,
     el script normalmente se detiene. Aquí solo nos aseguramos de registrarlo. */
  if (in_array($errno, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
    // Dejar que PHP maneje la detención si es un error fatal que nuestro manejador no puede prevenir
  }
  return true; // Indicar que el error ha sido manejado (previene el manejador estándar de PHP para no-fatales)
}

/**
 * Formatea un backtrace para legibilidad.
 * @param array $backtrace El array de backtrace de debug_backtrace().
 * @param bool $shortFormat Si es true, genera un formato más conciso.
 * @return string El backtrace formateado.
 */
function formatBacktrace(array $backtrace, bool $shortFormat = false): string {
  if (empty($backtrace)) return "[No backtrace available]";

  $output = "";
  if (!$shortFormat) $output .= str_repeat("-", 40) . "\n";

  foreach ($backtrace as $index => $trace) {
    $file = $trace['file'] ?? '[internal function]';
    $line = $trace['line'] ?? '-';
    $function = $trace['function'] ?? '[unknown_function]';
    $class = $trace['class'] ?? '';
    $type = $trace['type'] ?? ''; // '->' o '::'

    if ($shortFormat) {
      $output .= sprintf("#%d %s%s%s() called at [%s:%s]\n",
          $index,
          $class,
          $type,
          $function,
          basename($file), // Solo basename para formato corto
          $line
      );
    } else {
      $output .= sprintf("#%d %s(%s): ", $index, $file, $line);
      if (!empty($class)) {
        $output .= $class . $type;
      }
      $output .= $function . "(";
      if (isset($trace['args']) && !empty($trace['args'])) {
        $args = array_map(function ($arg) {
          if (is_object($arg)) {
            return get_class($arg) . " Object";
          } elseif (is_array($arg)) {
            return "Array[" . count($arg) . "]";
          } elseif (is_string($arg)) {
            return "'" . (strlen($arg) > 30 ? substr($arg, 0, 27) . "..." : $arg) . "'";
          } elseif (is_bool($arg)) {
            return $arg ? 'true' : 'false';
          } elseif (is_null($arg)) {
            return 'null';
          } else {
            return (string)$arg;
          }
        }, $trace['args']);
        $output .= implode(', ', $args);
      }
      $output .= ")\n";
    }
  }
  if (!$shortFormat) $output .= str_repeat("-", 40) . "\n";
  return rtrim($output);
}

/**
 * Helper para convertir constantes de error de PHP a string.
 */
function phpErrorConstantToString(int $errno): string {
  $errors = [
      E_ERROR => 'E_ERROR', E_WARNING => 'E_WARNING', E_PARSE => 'E_PARSE', E_NOTICE => 'E_NOTICE',
      E_CORE_ERROR => 'E_CORE_ERROR', E_CORE_WARNING => 'E_CORE_WARNING', E_COMPILE_ERROR => 'E_COMPILE_ERROR',
      E_COMPILE_WARNING => 'E_COMPILE_WARNING', E_USER_ERROR => 'E_USER_ERROR', E_USER_WARNING => 'E_USER_WARNING',
      E_USER_NOTICE => 'E_USER_NOTICE', E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
      E_DEPRECATED => 'E_DEPRECATED', E_USER_DEPRECATED => 'E_USER_DEPRECATED'
  ];
  return $errors[$errno] ?? (string)$errno;
}


// Registrar el manejador de errores personalizado
// Es importante registrarlo después de definir la clase Log y las funciones helper
// set_error_handler('\\bX\\errorHandler'); // Ya lo tienes
// Considera también un manejador de excepciones y un manejador de shutdown para errores fatales no capturables por set_error_handler
register_shutdown_function(function () {
    $lastError = error_get_last();
    if ($lastError && in_array($lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        // Ya fue (o debería haber sido) manejado por set_error_handler si es posible,
        // pero esto captura algunos que set_error_handler no puede.
        // Evita doble log si ya se manejó.
        // Esta es una lógica simplificada; un sistema de log robusto tendría más cuidado con los duplicados.
        if (function_exists('\bX\errorHandlerNotified') && \bX\errorHandlerNotified($lastError['message'])) return;

        \bX\Log::logError(
            sprintf("FATAL SHUTDOWN: %s: %s in %s on line %d",
                phpErrorConstantToString($lastError['type']),
                $lastError['message'],
                $lastError['file'],
                $lastError['line']
            ),
            ['shutdown_error_details' => $lastError]
        );
    }
});

set_exception_handler(function ($exception) {
  \bX\Log::logError(
      "UNCAUGHT EXCEPTION: " . $exception->getMessage(),
      [
          'exception_class' => get_class($exception),
          'file' => $exception->getFile(),
          'line' => $exception->getLine(),
          'code' => $exception->getCode(),
          'trace' => \bX\formatBacktrace($exception->getTrace()) // Usar tu helper
      ]
  );
  // Aquí podrías mostrar una página de error genérica al usuario si es una aplicación web
  // y no quieres que vea el error de PHP por defecto.
  // Ejemplo:
  // if (php_sapi_name() !== 'cli' && !headers_sent()) {
  //     http_response_code(500);
  //     echo "An internal server error occurred. Please try again later.";
  // }
  // exit(1); // Detener el script después de una excepción no capturada
});

set_error_handler("\\bX\\errorHandler");
