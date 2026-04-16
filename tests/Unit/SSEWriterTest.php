<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Unit;

use PHPdot\Routing\RouterRT\SSEWriter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SSEWriterTest extends TestCase
{
    private string $output;
    private bool $closed;
    private SSEWriter $writer;

    protected function setUp(): void
    {
        $this->output = '';
        $this->closed = false;
        $this->writer = new SSEWriter(
            writeFn: function (string $data): bool {
                $this->output .= $data;
                return true;
            },
            closeFn: function (): void {
                $this->closed = true;
            },
        );
    }

    #[Test]
    public function sendsNamedEvent(): void
    {
        $this->writer->event('update', 'payload');

        self::assertSame("event: update\ndata: payload\n\n", $this->output);
    }

    #[Test]
    public function sendsEventWithId(): void
    {
        $this->writer->event('update', 'payload', '42');

        self::assertSame("id: 42\nevent: update\ndata: payload\n\n", $this->output);
    }

    #[Test]
    public function sendsEventWithArrayData(): void
    {
        $this->writer->event('update', ['key' => 'value']);

        self::assertSame("event: update\ndata: {\"key\":\"value\"}\n\n", $this->output);
    }

    #[Test]
    public function sendsMultilineEventData(): void
    {
        $this->writer->event('log', "line1\nline2");

        self::assertSame("event: log\ndata: line1\ndata: line2\n\n", $this->output);
    }

    #[Test]
    public function sendsUnnamedData(): void
    {
        $this->writer->data('hello');

        self::assertSame("data: hello\n\n", $this->output);
    }

    #[Test]
    public function sendsArrayAsData(): void
    {
        $this->writer->data(['a' => 1]);

        self::assertSame("data: {\"a\":1}\n\n", $this->output);
    }

    #[Test]
    public function sendsComment(): void
    {
        $this->writer->comment('keep-alive');

        self::assertSame(": keep-alive\n\n", $this->output);
    }

    #[Test]
    public function sendsRetry(): void
    {
        $this->writer->retry(5000);

        self::assertSame("retry: 5000\n\n", $this->output);
    }

    #[Test]
    public function closesStream(): void
    {
        self::assertFalse($this->writer->isClosed());

        $this->writer->close();

        self::assertTrue($this->writer->isClosed());
        self::assertTrue($this->closed);
    }

    #[Test]
    public function detectsClientDisconnect(): void
    {
        $writer = new SSEWriter(
            writeFn: fn(string $data): bool => false,
            closeFn: fn(): null => null,
        );

        $writer->event('update', 'will fail');

        self::assertTrue($writer->isClosed());
    }

    #[Test]
    public function keepAliveDetectsDisconnect(): void
    {
        $writeCount = 0;
        $writer = new SSEWriter(
            writeFn: function (string $data) use (&$writeCount): bool {
                $writeCount++;
                return $writeCount <= 1;
            },
            closeFn: fn(): null => null,
        );

        $writer->event('first', 'ok');
        self::assertFalse($writer->isClosed());

        $writer->event('second', 'will fail');
        self::assertTrue($writer->isClosed());
    }

    #[Test]
    public function ignoresWritesAfterClose(): void
    {
        $this->writer->close();

        $this->writer->event('update', 'ignored');
        $this->writer->data('ignored');
        $this->writer->comment('ignored');
        $this->writer->retry(1000);

        self::assertSame('', $this->output);
    }

    #[Test]
    public function markClosedDoesNotCallCloseFn(): void
    {
        $this->writer->markClosed();

        self::assertTrue($this->writer->isClosed());
        self::assertFalse($this->closed);
    }
}
