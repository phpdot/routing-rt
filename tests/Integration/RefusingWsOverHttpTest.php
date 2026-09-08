<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use PHPdot\Routing\RouterRT\Tests\Support\WsHarness;
use PHPUnit\Framework\Attributes\Test;

/**
 * The refusing half of the WebSocket-over-HTTP proof: the channel's
 * middleware never calls next, the open is rejected after the protocol
 * upgrade, and the server closes the connection — no frame ever comes back.
 */
final class RefusingWsOverHttpTest extends WsHarness
{
    #[Test]
    public function aRefusingMiddlewareClosesTheConnection(): void
    {
        $socket = $this->openWebSocket($this->portNumber(), '/chat/general');

        fwrite($socket, $this->encodeMaskedTextFrame('{"event":"message","data":{"text":"hi"}}'));

        /*
         * A rejected open means no binding: the server closes the fd, and the
         * client reads a WebSocket CLOSE frame (opcode 8, FIN set) where an
         * accepted channel would have answered the echo.
         */
        [$firstByte, $payload] = $this->readFrame($socket);

        self::assertSame(0x88, $firstByte, 'the answer is a close frame, never a message');
        self::assertNotSame('echo', json_decode($payload, true)['event'] ?? null);
    }

    /**
     * @return string
     */
    protected function scenario(): string
    {
        return 'refuse';
    }
}
