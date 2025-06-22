<?php

namespace bX;

class Args {

  public static $OPT = [];
  public static array $input = [];

  public function __construct(string $short_opt = '', array $long_opt = []) {
    $method = $this->determineInputMethod();

    switch ($method) {
      case 'CLI':
        if ($_SERVER['argc'] > 1) {
        $this->processCommandLineArguments($_SERVER['argv'] ?? []);
        } else {
          $this->processStdinData();
        }
      break;
      case 'GET':
        if(!empty($_GET)) $this->populateSuperGlobal($_GET, 'GET');
      break;
      case 'POST':
        if (!empty($_POST)) {
          $this->populateSuperGlobal($_POST, 'POST');
        }
        $this->processJsonInputStream('POST');
      break;
      case 'STDIN':
        $this->processStdinData();
      break;
    }
  }

  /**
   * Determina el método de entrada utilizado.
   *
   * @return string 'CLI', 'POST', 'GET', or 'STDIN'
   */
  private function determineInputMethod(): string {
    if (PHP_SAPI === 'cli') {
      return 'CLI';
    } elseif (!empty($_SERVER['REQUEST_METHOD'])) {
      if($_SERVER['REQUEST_METHOD'] === 'POST'){
        return 'POST';
      }
      if($_SERVER['REQUEST_METHOD'] === 'GET'){
        return 'GET';
      }
    }
    return 'STDIN'; # JUST IN CASE
  }

  /**
   * Processes the raw input stream for JSON data.
   * This is essential for handling API requests with JSON bodies.
   * @param string $method The HTTP method ('POST', 'PUT', etc.)
   */
  private function processJsonInputStream(string $method): void {
    $jsonInput = file_get_contents('php://input');
    if (!empty($jsonInput)) {
      $data = json_decode($jsonInput, true);
      if (json_last_error() === JSON_ERROR_NONE) {
        $this->populateSuperGlobal($data, $method);
      }
    }
  }

  /**
   * Procesa los argumentos de la línea de comandos.
   *
   * @param array $argv Argumentos de la línea de comandos.
   */
  private function processCommandLineArguments(array $argv): void {
    $args = [];
    $forcePost = false;

    for ($i = 1; $i < count($argv); $i++) {
      $arg = $argv[$i];

      // Argumentos largos (--arg)
      if (str_starts_with($arg, '--')) {
        $arg = substr($arg, 2);
        if (str_contains($arg, '=')) {
          [$key, $value] = explode('=', $arg, 2);
          $args[$key] = $this->processArgumentValue($value);
        } else {
          $args[$arg] = true;
        }
      }
      // Argumentos cortos (-a)
      elseif (str_starts_with($arg, '-')) {
        $arg = substr($arg, 1);
        // Multiples argumentos cortos (-abc)
        if(strlen($arg)>1){
          for($c=0;$c<strlen($arg);$c++){
            $args[$arg[$c]] = true;
          }
        } else if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '-')) {
          $args[$arg] = $this->processArgumentValue($argv[++$i]);
        } else {
          $args[$arg] = true;
        }
      }
      else {
        if (str_contains($arg, '=')) {
          [$key, $value] = explode('=', $arg, 2);
          $args[$key] = $this->processArgumentValue($value);
        } else {
          $args[$arg] = true;
        }
      }
      if((isset($args['method']) && $args['method']==='POST') || (isset($args['POST']) && $args['POST']) ){
        $forcePost = true;
      }
    }

    $this->populateSuperGlobal($args, ($forcePost ? 'POST' : 'GET')); //Forzar POST si existe el argumento method=POST
  }


  /**
   * Procesa el valor de un argumento, incluyendo la decodificación JSON si es necesario.
   *
   * @param string $value Valor del argumento.
   * @return mixed Valor procesado.
   */
  private function processArgumentValue(string $value): mixed {
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
  /**
   * Procesa los datos de entrada STDIN.
   *
   */
  private function processStdinData(): void {
    $stdin = @stream_get_contents(STDIN, -1, 0);
    if (!empty($stdin)) {
      $data = [];
      // Check if the stream is JSON
      if (str_starts_with($stdin, '{') || str_starts_with($stdin, '[')) {
        $decodedValue = json_decode($stdin, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $data = $decodedValue;
        }
      } else {
        parse_str($stdin, $data);
      }
      // search for force POST
      $forcePost = false;
      if (isset($data['method']) && $data['method'] === 'POST') {
        $forcePost = true;
        unset($data['method']); // Eliminar el indicador del array de datos
      }

      $this->populateSuperGlobal($data, ($forcePost ? 'POST' : 'GET'));
    }
  }

  /**
   * Populates the static properties with the provided data.
   * @param array  $data   Data to populate with.
   * @param string $method The source method ('GET', 'POST', 'CLI').
   */
  private function populateSuperGlobal(array $data, string $method): void {
    if ($method === 'POST') {
      $_POST = array_merge($_POST, $data);
      #Args::$OPT = array_merge(Args::$OPT, $_POST);
    } else {
      $_GET = array_merge($_GET, $data);
      #Args::$OPT = array_merge(Args::$OPT, $_GET);
    }

    self::$OPT = array_merge(self::$OPT, $data);
    self::$input = array_merge(self::$input, $data);
  }
}