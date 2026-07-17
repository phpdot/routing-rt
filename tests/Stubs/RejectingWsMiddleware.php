<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use Closure;
use PHPdot\Realtime\Socket;
use PHPdot\Routing\RouterRT\Ack;
use PHPdot\Routing\RouterRT\Contract\WebSocketMiddleware;

/**
 * A WS middleware that REJECTS the connection — it never calls $next, so the
 * subscribe never runs and dispatchWsOpen returns false (transport disconnects).
 * This is the per-connection auth gate (e.g. logged-out user → no channel).
 */
final class RejectingWsMiddleware implements WebSocketMiddleware
{
    /**
     * @param array<string, int|string> $params
     * @param array<mixed, mixed> $data
     */
    public function process(Socket $socket, string $event, array $params, array $data, Ack|null $ack, Closure $next): void
    {
        // Reject: do not call $next().
    }
}
