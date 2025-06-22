<?php

namespace bX;

class Args {

  public static $OPT = [];

  public function __construct(string $short_opt = '', array $long_opt = []) {
    // Determinar el método de entrada (GET, POST o CLI)
    $method = $this->determineInputMethod();

    // Procesar argumentos de la línea de comandos
    if ($method === 'CLI' && !empty($_SERVER['argv'])) {
      $this->processCommandLineArguments($_SERVER['argv']);
    }

    // Procesar datos de entrada POST, GET o STDIN
    if ($method === 'POST' && !empty($_POST)) {
      $this->populateSuperGlobal($_POST, 'POST');
    } elseif ($method === 'GET' && !empty($_GET)) {
      $this->populateSuperGlobal($_GET, 'GET');
    } elseif ($method === 'STDIN') {
      $this->processStdinData();
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
    return 'STDIN';
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
      //Verificar si el stream es un JSON
      if (str_starts_with($stdin, '{') || str_starts_with($stdin, '[')) {
        $decodedValue = json_decode($stdin, true);
        if (json_last_error() === JSON_ERROR_NONE) {
          $data = $decodedValue;
        }
      } else {
        // Intentar analizar como pares clave=valor
        $lines = explode('&', $stdin);
        foreach ($lines as $line) {
          if (str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $data[$key] = $this->processArgumentValue(urldecode($value));
          }
        }
      }

      // Buscar un indicador para forzar POST
      $forcePost = false;
      if (isset($data['method']) && $data['method'] === 'POST') {
        $forcePost = true;
        unset($data['method']); // Eliminar el indicador del array de datos
      }

      $this->populateSuperGlobal($data, ($forcePost ? 'POST' : 'GET'));
    }
  }

  /**
   * Llena las variables superglobales $_GET o $_POST con los datos proporcionados.
   *
   * @param array  $data   Datos para llenar la superglobal.
   * @param string $method 'GET' o 'POST', indica la superglobal a llenar.
   */
  private function populateSuperGlobal(array $data, string $method): void {
    if ($method === 'POST') {
      $_POST = array_merge($_POST, $data);
      Args::$OPT = array_merge(Args::$OPT, $_POST);
    } else {
      $_GET = array_merge($_GET, $data);
      Args::$OPT = array_merge(Args::$OPT, $_GET);
    }
  }
}