<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Realtime\Socket;
use PHPdot\Routing\RouterRT\Contract\ChannelController;

/**
 * Captures the raw `id` param + its runtime type — used to prove WS channels get
 * the router's type coercion ({id:int} → int) exactly like HTTP routes.
 */
final class TypedParamControllerStub implements ChannelController
{
    public int|string|null $id = null;

    public string $idType = 'null';

    /**
     * @param array<string, int|string> $params
     */
    public function subscribe(Socket $socket, array $params): void
    {
        $this->id = $params['id'] ?? null;
        $this->idType = get_debug_type($params['id'] ?? null);
    }

    /**
     * @param array<string, int|string> $params
     */
    public function unsubscribe(Socket $socket, array $params): void {}
}
