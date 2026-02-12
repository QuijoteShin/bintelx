<?php # package/ws/fingerprint.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Crypto;
use bX\Args;

# Genera xxh128 determinista a partir de componentes del dispositivo
# El cliente recolecta canvas, WebGL, audio, hardware, screen, math, fonts, media
# y envía los componentes al server. El server ordena y hashea con xxh128.
# Funciona vía WS (route: /ws/fingerprint) y vía HTTP (POST /fingerprint en onRequest)
Router::register(['POST'], 'fingerprint', function(...$params) {
    $components = Args::$OPT['components'] ?? null;
    if (!is_array($components) || empty($components)) {
        return Response::json(['success' => false, 'error' => 'components array required'], 400);
    }
    ksort($components);
    $parts = [];
    foreach ($components as $key => $value) {
        $parts[] = $key . ':' . (is_string($value) ? $value : json_encode($value));
    }
    $raw = implode('||', $parts);
    $hash = Crypto::xxh128($raw);
    return Response::json([
        'success' => true,
        'hash' => $hash,
        'algorithm' => 'xxh128',
        'components_count' => count($components)
    ]);
}, ROUTER_SCOPE_PUBLIC);
