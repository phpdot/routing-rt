<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Unit;

use PHPdot\Routing\RouterRT\Frame;
use PHPdot\Routing\RouterRT\Opcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameTest extends TestCase
{
    #[Test]
    public function textFrame(): void
    {
        $frame = new Frame('hello', Opcode::Text);

        self::assertSame('hello', $frame->data);
        self::assertSame(Opcode::Text, $frame->opcode);
    }

    #[Test]
    public function binaryFrame(): void
    {
        $frame = new Frame("\x00\x01\x02", Opcode::Binary);

        self::assertSame("\x00\x01\x02", $frame->data);
        self::assertSame(Opcode::Binary, $frame->opcode);
    }
}
