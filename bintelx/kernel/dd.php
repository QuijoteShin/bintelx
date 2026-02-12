<?php
# bintelx/kernel/dd.php
# Debug helper — compatible con Channel Server (no usar exit() en workers)

function dd() {
    $args = func_get_args();
    $output = '';
    foreach ($args as $arg) {
        $output .= print_r($arg, true) . "\n";
    }
    # En Channel Server: lanzar excepción en vez de matar el Worker
    if (class_exists('Swoole\Coroutine') && \Swoole\Coroutine::getCid() > 0) {
        \bX\Log::logWarning("dd() en Channel context: " . substr($output, 0, 500));
        throw new \RuntimeException("dd() en Channel:\n" . $output);
    }
    # FPM/CLI: comportamiento original
    foreach ($args as $arg) {
        var_dump($arg);
    }
    exit();
}
