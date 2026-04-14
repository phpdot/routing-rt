<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT;

enum Opcode: int
{
    case Text = 1;
    case Binary = 2;
}
