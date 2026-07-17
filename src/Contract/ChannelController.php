<?php

declare(strict_types=1);

/**
 * Channel controller — owns the semantics of a channel route. Registered via
 * RouterRT::ws('/chat/{room}', ...); the router calls subscribe() when a client
 * subscribes to a channel matching the route pattern, passing the NAMED route
 * params (e.g. ['room' => 'general'] for channel '/chat/general').
 *
 * Event methods (onMessage, onTyping, etc.) are convention-based — NOT declared
 * in this interface. The router dispatches by method name: event 'message' →
 * onMessage($socket, $params, $data, $ack).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Routing\RouterRT\Contract;

use PHPdot\Realtime\Socket;

interface ChannelController
{
    /**
     * Called when a client subscribes to a channel matching this route.
     *
     * @param Socket $socket The subscribing socket.
     * @param array<string, int|string> $params Named route params (e.g. ['room' => 'general']).
     *
     * @return void
     */
    public function subscribe(Socket $socket, array $params): void;

    /**
     * Called when a client unsubscribes from a channel.
     *
     * @param Socket $socket The unsubscribing socket.
     * @param array<string, int|string> $params Named route params.
     *
     * @return void
     */
    public function unsubscribe(Socket $socket, array $params): void;
}
