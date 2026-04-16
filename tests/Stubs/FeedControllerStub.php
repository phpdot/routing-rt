<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Routing\RouterRT\Contracts\SSEController;
use PHPdot\Routing\RouterRT\SSEWriter;

final class FeedControllerStub implements SSEController
{
    public bool $streamed = false;

    public function stream(SSEWriter $writer): void
    {
        $writer->event('ping', 'pong');
        $this->streamed = true;
    }
}
