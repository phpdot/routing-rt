<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use PHPdot\Routing\RouterRT\Tests\Support\SseHarness;
use PHPUnit\Framework\Attributes\Test;

/**
 * The proceeding half of the SSE-over-HTTP proof: the route's middleware calls
 * next, and the frames arrive on the socket.
 */
final class ProceedingSseOverHttpTest extends SseHarness
{
    #[Test]
    public function theStreamArrivesThroughTheMiddleware(): void
    {
        $response = $this->get('/feed');

        self::assertStringContainsString('200', $this->statusLine($response));
        self::assertStringContainsString('text/event-stream', $response);
        self::assertStringContainsString('event: tick', $response);
        self::assertStringContainsString('event: done', $response);
    }

    /**
     * @return string
     */
    protected function scenario(): string
    {
        return 'proceed';
    }
}
