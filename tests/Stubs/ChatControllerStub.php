<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Routing\RouterRT\Connection;
use PHPdot\Routing\RouterRT\Contracts\WebSocketController;
use PHPdot\Routing\RouterRT\Frame;

final class ChatControllerStub implements WebSocketController
{
    /** @var list<string> */
    public array $events = [];

    public function onOpen(Connection $conn): void
    {
        $this->events[] = 'open';
    }

    public function onMessage(Connection $conn, Frame $frame): void
    {
        $this->events[] = 'message:' . $frame->data;
    }

    public function onClose(Connection $conn, int $code, string $reason): void
    {
        $this->events[] = "close:{$code}:{$reason}";
    }
}
