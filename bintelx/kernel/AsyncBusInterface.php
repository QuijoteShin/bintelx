<?php

namespace bX\Core\Async;

/**
 * Agnostic Async Bus Contract.
 * Decouples the application from the transport layer (Swoole Task, NATS, Redis, RabbitMQ).
 */
interface AsyncBusInterface
{
    /**
     * Publishes a job to the bus.
     *
     * @param string $subject The topic or channel (e.g., 'ai.vectorize', 'email.send')
     * @param array|object $payload Job data (Array or DTO)
     * @param string|null $correlationId Unique ID for request tracing (TraceID)
     * @return mixed Generated Job ID (TaskID in Swoole, Sequence in NATS)
     */
    public function publish(string $subject, array|object $payload, ?string $correlationId = null): mixed;

    /**
     * Executes an internal API endpoint asynchronously.
     * Simulates an internal HTTP request without network overhead.
     *
     * @param string $uri Internal route (e.g., '/api/internal/stats')
     * @param string $method HTTP Method (GET, POST, etc.)
     * @param array $data Request body
     * @param array $headers Simulated headers
     * @return mixed Task ID
     */
    public function executeEndpoint(string $uri, string $method = 'POST', array $data = [], array $headers = []): mixed;
}
