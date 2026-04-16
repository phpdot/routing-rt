<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Contracts;

use PHPdot\Routing\Contracts\ControllerInterface;
use PHPdot\Routing\RouterRT\Connection;
use PHPdot\Routing\RouterRT\Frame;

interface WebSocketController extends ControllerInterface
{
    public function onOpen(Connection $conn): void;

    public function onMessage(Connection $conn, Frame $frame): void;

    public function onClose(Connection $conn, int $code, string $reason): void;
}
