<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Routing\RouterRT\Contracts\WebSocketController;
use PHPdot\Routing\RouterRT\Frame;

final class ChatControllerStub implements WebSocketController
{
    /** @var list<string> */
    public array $events = [];

    public function onOpen(): void
    {
        $this->events[] = 'open';
    }

    public function onMessage(Frame $frame): void
    {
        $this->events[] = 'message:' . $frame->data;
    }

    public function onClose(int $code, string $reason): void
    {
        $this->events[] = "close:{$code}:{$reason}";
    }
}
