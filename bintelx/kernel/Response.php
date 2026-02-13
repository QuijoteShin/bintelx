<?php # bintelx/kernel/Response.php

namespace bX;

# Agnostic Response Wrapper
# Standardizes API responses across HTTP, WebSocket, and CLI contexts
class Response
{
    protected int $statusCode = 200;
    protected array $headers = [];
    protected mixed $data = null;
    protected string $type = 'json'; # json, raw, file, stream

    protected ?string $rawContent = null;
    protected ?string $filePath = null;
    protected ?string $downloadName = null;
    protected $streamGenerator = null;

    # Static constructors for fluent interface

    # Para respuestas estándar con wrapper success/message/data
    public static function success(mixed $data, string $message = 'OK'): self
    {
        $response = new self();
        $response->data = [
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => ['timestamp' => time()]
        ];
        $response->statusCode = 200;
        return $response;
    }

    # Para errores con wrapper success/message
    public static function error(string $message, int $code = 400): self
    {
        $response = new self();
        $response->data = [
            'success' => false,
            'message' => $message,
            'meta' => ['timestamp' => time()]
        ];
        $response->statusCode = $code;
        return $response;
    }

    # Para JSON custom (data tal cual, sin wrapper)
    public static function json(mixed $data, int $code = 200): self
    {
        $response = new self();
        $response->data = $data;
        $response->statusCode = $code;
        $response->type = 'json';
        return $response;
    }

    # Para TOON format (human-readable, compact)
    public static function toon(mixed $data, int $code = 200): self
    {
        $response = new self();
        $response->rawContent = \bX\Toon\Toon::encode($data);
        $response->statusCode = $code;
        $response->type = 'raw';
        $response->headers['Content-Type'] = 'text/toon; charset=utf-8';
        return $response;
    }

    # Para contenido RAW (HTML, texto plano, etc)
    public static function raw(string $content, string $contentType = 'text/plain'): self
    {
        $response = new self();
        $response->rawContent = $content;
        $response->type = 'raw';
        $response->headers['Content-Type'] = $contentType;
        return $response;
    }

    # Para descargas de archivos
    public static function file(string $path, ?string $downloadName = null): self
    {
        $response = new self();
        $response->filePath = $path;
        $response->downloadName = $downloadName ?? basename($path);
        $response->type = 'file';
        return $response;
    }

    # Para streaming (SSE, video, etc)
    public static function stream(callable $generator): self
    {
        $response = new self();
        $response->streamGenerator = $generator;
        $response->type = 'stream';
        return $response;
    }

    # Fluent setters
    public function withStatus(int $code): self
    {
        $this->statusCode = $code;
        return $this;
    }

    public function withHeader(string $name, string $value): self
    {
        $this->headers[$name] = $value;
        return $this;
    }

    # Send response
    public function send(): void
    {
        # Set HTTP status code
        if (!headers_sent()) {
            http_response_code($this->statusCode);
        }

        switch ($this->type) {
            case 'json':
                $this->sendJson();
                break;

            case 'raw':
                $this->sendRaw();
                break;

            case 'file':
                $this->sendFile();
                break;

            case 'stream':
                $this->sendStream();
                break;
        }
    }

    # NOTA CHANNEL: header() y http_response_code() son silenciosamente ignorados
    # en el Channel Server. El body se captura via ob_start/ob_get_clean.
    # Si se agregan endpoints HTTP REST al Channel, crear ResponseAdapter.
    protected function sendJson(): void
    {
        # Set JSON header
        if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = 'application/json; charset=utf-8';
        }

        # Send headers
        foreach ($this->headers as $name => $value) {
            if (!headers_sent()) {
                header("$name: $value");
            }
        }

        # Send data (tal cual, el dev controla la estructura)
        echo json_encode($this->data);
    }

    protected function sendRaw(): void
    {
        # Send headers
        foreach ($this->headers as $name => $value) {
            if (!headers_sent()) {
                header("$name: $value");
            }
        }

        echo $this->rawContent;
    }

    protected function sendFile(): void
    {
        if (!file_exists($this->filePath)) {
            self::error('File not found', 404)->send();
            return;
        }

        # Clean buffers SOLO para files (evitar corrupción)
        while (ob_get_level()) {
            ob_end_clean();
        }

        # Auto-detect Content-Type
        if (!isset($this->headers['Content-Type'])) {
            $mimeType = mime_content_type($this->filePath) ?: 'application/octet-stream';
            $this->headers['Content-Type'] = $mimeType;
        }

        # Download headers
        $this->headers['Content-Disposition'] = 'attachment; filename="' . $this->downloadName . '"';
        $this->headers['Content-Length'] = filesize($this->filePath);
        $this->headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';

        # Send headers
        foreach ($this->headers as $name => $value) {
            if (!headers_sent()) {
                header("$name: $value");
            }
        }

        # Send file
        readfile($this->filePath);
    }

    protected function sendStream(): void
    {
        # NO tocar buffers - el Router ya los manejó

        # SSE headers
        if (!isset($this->headers['Content-Type'])) {
            $this->headers['Content-Type'] = 'text/event-stream';
        }

        if (!isset($this->headers['X-Accel-Buffering'])) {
            $this->headers['X-Accel-Buffering'] = 'no';
        }

        $this->headers['Cache-Control'] = 'no-cache';
        $this->headers['Connection'] = 'keep-alive';

        # Send headers
        foreach ($this->headers as $name => $value) {
            if (!headers_sent()) {
                header("$name: $value");
            }
        }

        # Execute generator
        if (is_callable($this->streamGenerator)) {
            call_user_func($this->streamGenerator);
        }

        flush();
    }
}
