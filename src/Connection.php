<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT;

use Closure;
use Psr\Http\Message\ServerRequestInterface;

final class Connection
{
    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, mixed> */
    private array $attributes = [];

    /**
     * @param int $fd Connection file descriptor
     * @param Closure(string): bool $sendFn Send text frame
     * @param Closure(string): bool $sendBinaryFn Send binary frame
     * @param Closure(int, string): bool $closeFn Close connection
     * @param ServerRequestInterface $upgradeRequest Original upgrade request
     */
    public function __construct(
        private readonly int $fd,
        private readonly Closure $sendFn,
        private readonly Closure $sendBinaryFn,
        private readonly Closure $closeFn,
        private readonly ServerRequestInterface $upgradeRequest,
    ) {}

    /**
     * Get the connection file descriptor.
     */
    public function id(): int
    {
        return $this->fd;
    }

    /**
     * Send text data. Arrays are JSON-encoded automatically.
     *
     * @param string|array<string, mixed> $data
     */
    public function send(string|array $data): bool
    {
        $payload = is_array($data) ? json_encode($data, JSON_THROW_ON_ERROR) : $data;
        return ($this->sendFn)($payload);
    }

    /**
     * Send binary frame.
     */
    public function sendBinary(string $data): bool
    {
        return ($this->sendBinaryFn)($data);
    }

    /**
     * Close the connection.
     */
    public function close(int $code = 1000, string $reason = ''): bool
    {
        return ($this->closeFn)($code, $reason);
    }

    /**
     * Get a route parameter.
     */
    public function param(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * Get all route parameters.
     *
     * @return array<string, mixed>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * Get a request attribute (set by middleware — auth user, etc.)
     */
    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $this->upgradeRequest->getAttribute($key, $default);
    }

    /**
     * The original upgrade request.
     */
    public function request(): ServerRequestInterface
    {
        return $this->upgradeRequest;
    }

    /**
     * @internal Called by the framework after route matching.
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): void
    {
        $this->params = $params;
    }

    /**
     * @internal Called by the framework after middleware.
     * @param array<string, mixed> $attributes
     */
    public function setAttributes(array $attributes): void
    {
        $this->attributes = $attributes;
    }
}
