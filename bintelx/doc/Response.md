# bX\Response - API Response Wrapper

## Descripción

Clase agnóstica para estandarizar respuestas HTTP en Bintelx. Separa la lógica de negocio de la lógica de transporte HTTP.

**Ubicación:** `bintelx/kernel/Response.php`
**Namespace:** `bX\Response`

---

## API Reference

### Constructores Estáticos

#### `Response::success($data, $message = 'OK')`
Respuesta exitosa con wrapper estándar.

```php
return Response::success(['user_id' => 123], 'User created');
```

**Output:**
```json
{
  "success": true,
  "message": "User created",
  "data": {"user_id": 123},
  "meta": {"timestamp": 1764193848}
}
```

---

#### `Response::error($message, $code = 400)`
Respuesta de error con wrapper estándar.

```php
return Response::error('Invalid credentials', 401);
```

**Output (HTTP 401):**
```json
{
  "success": false,
  "message": "Invalid credentials",
  "meta": {"timestamp": 1764193848}
}
```

---

#### `Response::json($data, $code = 200)`
JSON custom sin wrapper (dev controla estructura).

```php
return Response::json(['openapi' => '3.1.0', ...], 200);
```

**Output:**
```json
{"openapi": "3.1.0", ...}
```

---

#### `Response::raw($content, $contentType = 'text/plain')`
Contenido RAW (HTML, XML, texto).

```php
return Response::raw($html, 'text/html; charset=utf-8');
```

---

#### `Response::file($path, $downloadName = null)`
Descarga de archivo con headers automáticos.

```php
return Response::file('/path/to/report.pdf', 'monthly_report.pdf');
```

**Headers automáticos:**
- `Content-Type` (auto-detect)
- `Content-Disposition: attachment`
- `Content-Length`
- Cache-Control

---

#### `Response::stream($generator)`
Streaming continuo (SSE, eventos en tiempo real).

```php
return Response::stream(function() {
    foreach ($events as $event) {
        echo "data: " . json_encode($event) . "\n\n";
        flush();
    }
});
```

**Headers automáticos:**
- `Content-Type: text/event-stream`
- `X-Accel-Buffering: no`
- `Cache-Control: no-cache`
- `Connection: keep-alive`

---

### Métodos Fluent

#### `withStatus($code)`
Establece código HTTP.

```php
return Response::json($data)->withStatus(201);
```

#### `withHeader($name, $value)`
Agrega header custom.

```php
return Response::json($data)
    ->withHeader('X-Custom-Header', 'value')
    ->withHeader('Cache-Control', 'max-age=3600');
```

---

## Ejemplos de Uso

### Endpoint de Autenticación

```php
Router::register(['POST'], 'login', function() {
  $result = AuthHandler::login(\bX\Args::$OPT);

  if ($result['success']) {
    return Response::json(['data' => $result], 200);
  } else {
    return Response::json(['data' => $result], 401);
  }
}, ROUTER_SCOPE_PUBLIC);
```

### Endpoint de Spec/Config

```php
Router::register(['GET'], 'config', function() {
  $config = ConfigHandler::getConfig();
  return Response::json($config);  // Sin wrapper
}, ROUTER_SCOPE_PUBLIC);
```

### Endpoint con Wrapper Estándar

```php
Router::register(['POST'], 'create', function() {
  $result = Handler::create(\bX\Args::$OPT);
  return Response::success($result, 'Resource created');
}, ROUTER_SCOPE_PRIVATE);
```

### Descarga de Archivo

```php
Router::register(['GET'], 'export/pdf', function() {
  $path = ReportHandler::generatePDF();
  return Response::file($path, 'report.pdf');
}, ROUTER_SCOPE_PRIVATE);
```

### Streaming de Eventos

```php
Router::register(['GET'], 'events/stream', function() {
  return Response::stream(function() {
    while ($event = EventQueue::next()) {
      echo "data: " . json_encode($event) . "\n\n";
      flush();
      usleep(100000); // 100ms
    }
  });
}, ROUTER_SCOPE_PRIVATE);
```

---

## Router Híbrido

El Router soporta **tres modos** simultáneamente:

### 1. Nuevo: Return Response Object
```php
return Response::success($data);
```

### 2. Nuevo: Return Data (auto-wrapping)
```php
return $data;  // Router lo envuelve en Response::success()
```

### 3. Legacy: Echo directo
```php
echo json_encode(['data' => $result]);  // Sigue funcionando
```

---

## Diferencias Clave

| Método | Wrapper | Control de Estructura | Uso |
|--------|---------|----------------------|-----|
| `Response::success()` | Sí (`{success, message, data}`) | Limitado | Endpoints estándar |
| `Response::error()` | Sí (`{success, message}`) | Limitado | Errores |
| `Response::json()` | No | Total | Specs, configs, exports |
| `Response::raw()` | No | Total | HTML, XML, texto |

---

## Manejo de Errores

### En Handler (Business):
```php
public static function getData() {
    try {
        return $data;
    } catch (\Exception $e) {
        throw $e;  // ✅ Dejar que Router maneje
    }
}
```

### En Endpoint:
```php
Router::register(['GET'], 'data', function() {
    try {
        $data = Handler::getData();
        return Response::success($data);
    } catch (\Exception $e) {
        return Response::error($e->getMessage(), 500);
    }
});
```

### Automático (Router catch):
El Router captura excepciones no manejadas y devuelve error 500 automáticamente.

---

## Migración desde Legacy

Ver: [RESPONSE_MIGRATION_PATTERN.md](../../docs/RESPONSE_MIGRATION_PATTERN.md)

**Checklist:**
- [ ] Handler limpio (sin `http_response_code`, `header`, `echo`)
- [ ] Endpoint usa `return Response::*`
- [ ] Tests pasan
- [ ] Respuesta idéntica o mejor

---

## Performance

- ✅ Zero overhead para legacy (`echo` directo)
- ✅ Minimal overhead para Response (una instancia + method call)
- ✅ Buffering manejado eficientemente
- ✅ Streaming sin buffers adicionales

---

**Última actualización:** 2025-11-26
