<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Contracts;

use PHPdot\Routing\Contracts\ControllerInterface;
use PHPdot\Routing\RouterRT\Frame;
use PHPdot\Routing\RouterRT\WebSocketConnection;

interface WebSocketController extends ControllerInterface
{
    public function onOpen(WebSocketConnection $conn): void;

    public function onMessage(WebSocketConnection $conn, Frame $frame): void;

    public function onClose(WebSocketConnection $conn, int $code, string $reason): void;
}
