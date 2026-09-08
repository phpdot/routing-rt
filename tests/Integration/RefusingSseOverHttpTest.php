<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use PHPdot\Routing\RouterRT\Tests\Support\SseHarness;
use PHPUnit\Framework\Attributes\Test;

/**
 * The refusing half of the SSE-over-HTTP proof: the same real server, the
 * route's middleware answering 401 without proceeding — no stream byte is
 * written and the request falls through to the HTTP pipeline.
 */
final class RefusingSseOverHttpTest extends SseHarness
{
    #[Test]
    public function aRefusingMiddlewareFallsThroughToThePipeline(): void
    {
        $response = $this->get('/feed');

        /*
         * The refused stream never starts; the server falls through to the
         * RouterRT's own HTTP handling, which has no HTTP route for /feed and
         * answers 404 — the pipeline's honest judgment for this wiring.
         */
        self::assertStringContainsString('404', $this->statusLine($response));
        self::assertStringNotContainsString('event: tick', $response, 'no stream byte may be written');
    }

    /**
     * @return string
     */
    protected function scenario(): string
    {
        return 'refuse';
    }
}
