<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Contracts\Server\ConnectionSenderInterface;

/**
 * In-memory ConnectionSenderInterface so the Hub can drive sockets with no server.
 */
final class FakeSender implements ConnectionSenderInterface
{
    /** @var array<int, list<string>> fd → frames pushed. */
    public array $sent = [];

    public function pushWs(int $fd, string $frame): bool
    {
        $this->sent[$fd][] = $frame;

        return true;
    }

    public function pingWs(int $fd): bool
    {
        return true;
    }

    public function disconnect(int $fd, int $code = 1000, string $reason = ''): bool
    {
        return true;
    }

    public function exists(int $fd): bool
    {
        return true;
    }
}
