<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use PHPdot\Routing\RouterRT\Tests\Support\WsHarness;
use PHPUnit\Framework\Attributes\Test;

/**
 * The proceeding half of the WebSocket-over-HTTP proof: the channel's
 * middleware calls next, and a message frame dispatched through the adapter
 * comes back as a real frame on the socket — echo and ack both.
 */
final class ProceedingWsOverHttpTest extends WsHarness
{
    #[Test]
    public function theMessageRoundTripsThroughTheChannel(): void
    {
        $socket = $this->openWebSocket($this->portNumber(), '/chat/general');

        fwrite($socket, $this->encodeMaskedTextFrame('{"event":"message","data":{"text":"hi"},"ack":7}'));

        $echo = json_decode($this->readTextFrame($socket), true, 8);
        $ack = json_decode($this->readTextFrame($socket), true, 8);

        self::assertSame('echo', $echo['event']);
        self::assertSame('general', $echo['data']['room'], 'the named param from the connection URL reached the controller');
        self::assertSame('hi', $echo['data']['text']);

        self::assertSame('ack', $ack['event']);
        self::assertSame(7, $ack['data']['ack'], 'the ack id rides inside data');
        self::assertTrue($ack['data']['result']['ok']);

        fclose($socket);
    }

    /**
     * @return int
     */
    protected function scenario(): string
    {
        return 'proceed';
    }
}
