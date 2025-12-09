<?php # custom/ws/endpoint.endpoint.php
namespace ws;

use bX\Router;
use bX\Response;
use bX\Log;
use bX\Async\SwooleAsyncBusAdapter;

/**
 * @endpoint   /ws/endpoint
 * @method     WS
 * @scope      ROUTER_SCOPE_PUBLIC
 * @purpose    Executes internal HTTP endpoints asynchronously via WebSocket
 * @body       (JSON) {"uri": "/api/...", "method": "GET|POST", "body": {...}, "headers": {...}, "correlation_id": "..."}
 * @tag        WebSocket
 */
Router::register(['GET', 'POST'], 'endpoint', function(...$params) {
    $server = $_SERVER['WS_SERVER'];
    $fd = $_SERVER['WS_FD'];
    $authTable = $_SERVER['WS_AUTH_TABLE'];

    $method = strtoupper($_POST['method'] ?? 'GET');
    $uri = $_POST['uri'] ?? null;
    $body = $_POST['body'] ?? [];
    $headers = $_POST['headers'] ?? [];
    $correlationId = $_POST['correlation_id'] ?? uniqid('req_', true);

    if (!$uri) {
        return Response::json([
            'type' => 'error',
            'message' => 'Missing uri parameter',
            'timestamp' => time()
        ]);
        return;
    }

    # Inject authenticated user's token if available
    if ($authTable->exists((string)$fd)) {
        $user = $authTable->get((string)$fd);
        # Si el usuario está autenticado, agregar su info al header
        $headers['X-Account-ID'] = (string)$user['account_id'];
        $headers['X-Profile-ID'] = (string)$user['profile_id'];
    }

    # Add client_fd to meta for response routing
    $headers['X-Trace-ID'] = $correlationId;
    $headers['X-Client-FD'] = (string)$fd;

    # Get AsyncBus from server context (initialized in onWorkerStart)
    # For now, we need to create it here or access it from somewhere
    # Since we can't access $this->asyncBus, we'll use the server directly
    $asyncBus = new SwooleAsyncBusAdapter($server);

    # Dispatch to Task Worker
    $taskId = $asyncBus->executeEndpoint($uri, $method, $body, $headers);

    # Send acknowledgment
    return Response::json([
        'type' => 'endpoint_queued',
        'correlation_id' => $correlationId,
        'task_id' => $taskId,
        'uri' => $uri,
        'method' => $method,
        'timestamp' => time()
    ]);

    Log::logInfo("WebSocket: Endpoint queued", [
        'fd' => $fd,
        'uri' => $uri,
        'method' => $method,
        'correlation_id' => $correlationId,
        'task_id' => $taskId
    ]);
}, ROUTER_SCOPE_PUBLIC);
