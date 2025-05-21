<?php
/*
 *

Carga archivos RC

[rc]
level = pre_warmup
class = RoutineClass
method = runRoutine
arguments[] = argument1
arguments[] = argument2

[rc]
level = during_warmup
function = runFunction
arguments[] = argument1
arguments[] = argument2

 *
 *
 */

class RunCommand {
    private $paths = array();
    private $config = array();

    public function addPath($path) {
        $this->paths[] = $path;
    }

    public function loadConfig() {
        foreach ($this->paths as $path) {
            $files = glob($path . '*.ini');
            foreach ($files as $file) {
                $config = parse_ini_file($file, true);
                $this->config = array_merge_recursive($this->config, $config);
            }
        }
    }

    public function run($level) {
        if (!isset($this->config[$level])) {
            return;
        }

        foreach ($this->config[$level] as $routine) {
            if (isset($routine['class'])) {
                $class = $routine['class'];
                $method = $routine['method'];
                $arguments = $routine['arguments'] ?? [];
                $instance = new $class();
                call_user_func_array([$instance, $method], $arguments);
            } elseif (isset($routine['function'])) {
                $function = $routine['function'];
                $arguments = $routine['arguments'] ?? [];
                call_user_func_array($function, $arguments);
            }
        }
    }
}