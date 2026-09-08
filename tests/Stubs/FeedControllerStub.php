<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Routing\RouterRT\Contract\SSEController;
use PHPdot\Routing\RouterRT\Transport\SSEWriter;

final class FeedControllerStub implements SSEController
{
    public bool $streamed = false;

    public function stream(SSEWriter $writer): void
    {
        $writer->event('ping', 'pong');
        $this->streamed = true;
    }
}
