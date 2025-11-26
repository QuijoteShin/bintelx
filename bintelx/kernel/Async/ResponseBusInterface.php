<?php # bintelx/kernel/Async/ResponseBusInterface.php

namespace bX\Async;

# Contract for sending responses from an asynchronous process (Worker) back to the client
# Abstracts the logic of WebSockets, SSE, or Push Notifications
interface ResponseBusInterface
{
    /**
     * Sends a success response to a specific file descriptor (fd).
     *
     * @param string $fd The client's file descriptor or connection ID.
     * @param mixed $data The data payload to send.
     * @param string $msg Optional status message.
     */
    public function sendSuccess(string $fd, mixed $data, string $msg = 'OK'): void;
    
    /**
     * Sends an error response to a specific file descriptor.
     *
     * @param string $fd The client's file descriptor.
     * @param string $error The error message.
     * @param int $code Error code (default 500).
     */
    public function sendError(string $fd, string $error, int $code = 500): void;
    
    /**
     * Sends a progress update (useful for long-running jobs like vectorization).
     *
     * @param string $fd The client's file descriptor.
     * @param int $percentage Progress percentage (0-100).
     * @param string $status Current status text (e.g., "Processing PDF...").
     */
    public function sendProgress(string $fd, int $percentage, string $status): void;
}
