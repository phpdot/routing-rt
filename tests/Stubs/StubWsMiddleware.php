<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use Closure;
use PHPdot\Realtime\Socket;
use PHPdot\Routing\RouterRT\Ack;
use PHPdot\Routing\RouterRT\Contract\WebSocketMiddleware;

/**
 * A pass-through WS middleware that records that it ran, then calls $next.
 */
final class StubWsMiddleware implements WebSocketMiddleware
{
    public bool $ran = false;

    /**
     * @param array<string, int|string> $params
     * @param array<mixed, mixed> $data
     */
    public function process(Socket $socket, string $event, array $params, array $data, Ack|null $ack, Closure $next): void
    {
        $this->ran = true;
        $next();
    }
}
