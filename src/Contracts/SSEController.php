<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Contracts;

use PHPdot\Routing\Contracts\ControllerInterface;
use PHPdot\Routing\RouterRT\SSEWriter;

interface SSEController extends ControllerInterface
{
    public function stream(SSEWriter $writer): void;
}
