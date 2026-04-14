<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT;

use Closure;

final class SSEWriter
{
    private bool $closed = false;

    /**
     * @param Closure(string): void $writeFn
     * @param Closure(): void $closeFn
     */
    public function __construct(
        private readonly Closure $writeFn,
        private readonly Closure $closeFn,
    ) {}

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
        ($this->writeFn)($payload);
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
        ($this->writeFn)($payload);
    }

    /**
     * Send a comment (keep-alive).
     */
    public function comment(string $text): void
    {
        if ($this->closed) {
            return;
        }

        ($this->writeFn)(": {$text}\n\n");
    }

    /**
     * Set client reconnection interval.
     */
    public function retry(int $ms): void
    {
        if ($this->closed) {
            return;
        }

        ($this->writeFn)("retry: {$ms}\n\n");
    }

    /**
     * Check if the client disconnected.
     */
    public function isClosed(): bool
    {
        return $this->closed;
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
}
