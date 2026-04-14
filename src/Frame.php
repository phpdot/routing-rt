<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT;

final readonly class Frame
{
    public function __construct(
        public string $data,
        public Opcode $opcode,
    ) {}
}
