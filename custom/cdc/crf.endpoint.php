<?php # custom/cdc/crf.endpoint.php
use bX\Router;
use cdc\CRF;

Router::register(['POST'], 'field', function() {
    header('Content-Type: application/json');
    $actor = \bX\Profile::$account_id ?? 'CDC_ACTOR';
    $data = json_decode(file_get_contents('php://input'), true) ?? [];

    $result = CRF::createFieldDefinition($data, $actor);
    echo json_encode($result);
}, ROUTER_SCOPE_WRITE);
