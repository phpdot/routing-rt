<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Contracts;

use PHPdot\Routing\Contracts\ControllerInterface;
use PHPdot\Routing\RouterRT\Frame;

interface WebSocketController extends ControllerInterface
{
    public function onOpen(): void;

    public function onMessage(Frame $frame): void;

    public function onClose(int $code, string $reason): void;
}
