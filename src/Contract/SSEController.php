<?php

declare(strict_types=1);

/**
 * Contract for a controller that streams Server-Sent Events to a client.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Routing\RouterRT\Contract;

use PHPdot\Routing\Contract\ControllerInterface;
use PHPdot\Routing\RouterRT\SSEWriter;

interface SSEController extends ControllerInterface
{
    /**
     * Stream.
     *
     * @param SSEWriter $writer
     *
     * @return void
     */
    public function stream(SSEWriter $writer): void;
}
