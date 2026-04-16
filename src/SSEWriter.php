<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT;

use Closure;

final class SSEWriter
{
    private bool $closed = false;
    private float $lastWriteTime;
    private const int KEEP_ALIVE_INTERVAL = 30;

    /**
     * @param Closure(string): bool $writeFn
     * @param Closure(): void $closeFn
     * @param string|null $lastEventId The Last-Event-ID header from the reconnecting client
     */
    public function __construct(
        private readonly Closure $writeFn,
        private readonly Closure $closeFn,
        private readonly string|null $lastEventId = null,
    ) {
        $this->lastWriteTime = microtime(true);
    }

    /**
     * Get the Last-Event-ID sent by the client on reconnection.
     * Returns null on first connection.
     */
    public function lastEventId(): string|null
    {
        return $this->lastEventId;
    }

    /**
     * Send a named event.
     *
     * @param string|array<string, mixed> $data
     */
    public function event(string $event, string|array $data, string|null $id = null): void
    {
        if ($this->closed) {
            return;
        }

        $payload = '';

        if ($id !== null) {
            $payload .= "id: {$id}\n";
        }

        $payload .= "event: {$event}\n";

        $encoded = is_array($data) ? json_encode($data, JSON_THROW_ON_ERROR) : $data;
        foreach (explode("\n", $encoded) as $line) {
            $payload .= "data: {$line}\n";
        }

        $payload .= "\n";
        $this->write($payload);
    }

    /**
     * Send unnamed data.
     *
     * @param string|array<string, mixed> $data
     */
    public function data(string|array $data): void
    {
        if ($this->closed) {
            return;
        }

        $encoded = is_array($data) ? json_encode($data, JSON_THROW_ON_ERROR) : $data;
        $payload = '';
        foreach (explode("\n", $encoded) as $line) {
            $payload .= "data: {$line}\n";
        }
        $payload .= "\n";
        $this->write($payload);
    }

    /**
     * Send a comment (keep-alive).
     */
    public function comment(string $text): void
    {
        if ($this->closed) {
            return;
        }

        $this->write(": {$text}\n\n");
    }

    /**
     * Set client reconnection interval.
     */
    public function retry(int $ms): void
    {
        if ($this->closed) {
            return;
        }

        $this->write("retry: {$ms}\n\n");
    }

    /**
     * Check if the client disconnected.
     * Sends a keep-alive comment if no data was written in the last 30 seconds.
     */
    public function isClosed(): bool
    {
        if ($this->closed) {
            return true;
        }

        if ((microtime(true) - $this->lastWriteTime) >= self::KEEP_ALIVE_INTERVAL) {
            $this->write(": keep-alive\n\n");
        }

        return false;
    }

    /**
     * Close the SSE stream.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ($this->closeFn)();
    }

    /**
     * @internal Called by the framework on client disconnect.
     */
    public function markClosed(): void
    {
        $this->closed = true;
    }

    private function write(string $payload): void
    {
        $ok = ($this->writeFn)($payload);
        if ($ok === false) {
            $this->closed = true;
            return;
        }
        $this->lastWriteTime = microtime(true);
    }
}
