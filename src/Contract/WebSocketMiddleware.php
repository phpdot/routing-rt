<?php

declare(strict_types=1);

/**
 * WebSocket middleware — runs before the controller method fires. Registered per-route
 * via the route's ->middleware(). Like PSR-15 but for WS events.
 *
 * Call $next() to pass to the next middleware / the controller method. Don't call $next()
 * to short-circuit (e.g., auth failure → disconnect the socket).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Routing\RouterRT\Contract;

use Closure;
use PHPdot\Realtime\Socket;
use PHPdot\Routing\RouterRT\Ack;

interface WebSocketMiddleware
{
    /**
     * Process an incoming WS event before it reaches the controller.
     *
     * @param Socket $socket The sending socket.
     * @param string $event The event name (e.g., 'message').
     * @param array<string, int|string> $params Named route params (e.g. ['room' => 'general']).
     * @param array<mixed, mixed> $data The event payload.
     * @param Ack|null $ack The ack context (null for fire-and-forget).
     * @param Closure(): void $next Call to proceed to the next middleware / controller method.
     *
     * @return void
     */
    public function process(Socket $socket, string $event, array $params, array $data, Ack|null $ack, Closure $next): void;
}
