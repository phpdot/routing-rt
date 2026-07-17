<?php

declare(strict_types=1);

/**
 * Ack — an acknowledgement context injected into controller methods when the client
 * requests confirmation. Null when the event is fire-and-forget (no ack field).
 *
 * The controller calls resolve() or reject() to send the response back. Null-safe
 * via ?Ack — $ack?->resolve() is a no-op when null.
 *
 * Wire format (response sent back to the client):
 * {"event":"ack","data":{"ack":N,"result":{...}}}   ← resolve
 * {"event":"ack","data":{"ack":N,"error":"..."}}     ← reject
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Routing\RouterRT;

use PHPdot\Realtime\Socket;

final class Ack
{
    private bool $responded = false;

    /**
     * __construct.
     *
     * @param int $id
     * @param Socket $socket
     */
    public function __construct(
        private readonly int $id,
        private readonly Socket $socket,
    ) {}

    /**
     * Send a success response with optional result data.
     *
     * @param mixed $result
     *
     * @return void
     */
    public function resolve(mixed $result = null): void
    {
        if ($this->responded) {
            return;
        }

        $this->responded = true;
        $this->socket->emit('ack', ['ack' => $this->id, 'result' => $result]);
    }

    /**
     * Send an error response.
     *
     * @param string $error
     *
     * @return void
     */
    public function reject(string $error): void
    {
        if ($this->responded) {
            return;
        }

        $this->responded = true;
        $this->socket->emit('ack', ['ack' => $this->id, 'error' => $error]);
    }
}
