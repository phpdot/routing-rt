<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Unit;

use PHPdot\Routing\RouterRT\Opcode;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OpcodeTest extends TestCase
{
    #[Test]
    public function textHasValue1(): void
    {
        self::assertSame(1, Opcode::Text->value);
    }

    #[Test]
    public function binaryHasValue2(): void
    {
        self::assertSame(2, Opcode::Binary->value);
    }

    #[Test]
    public function createsFromInt(): void
    {
        self::assertSame(Opcode::Text, Opcode::from(1));
        self::assertSame(Opcode::Binary, Opcode::from(2));
    }
}
