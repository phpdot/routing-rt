<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Realtime\Socket;
use PHPdot\Routing\RouterRT\Ack;
use PHPdot\Routing\RouterRT\Contract\ChannelController;

/**
 * A channel controller stub — records lifecycle + event dispatch for assertions.
 */
final class ChatControllerStub implements ChannelController
{
    /** @var list<string> */
    public array $events = [];

    /**
     * @param array<string, int|string> $params
     */
    public function subscribe(Socket $socket, array $params): void
    {
        $this->events[] = 'subscribe:' . $this->room($params);
    }

    /**
     * @param array<string, int|string> $params
     */
    public function unsubscribe(Socket $socket, array $params): void
    {
        $this->events[] = 'unsubscribe:' . $this->room($params);
    }

    /**
     * Convention-dispatched (event 'message' → onMessage).
     *
     * @param array<string, int|string> $params
     * @param array<mixed, mixed> $data
     */
    public function onMessage(Socket $socket, array $params, array $data, Ack|null $ack): void
    {
        $text = is_string($data['text'] ?? null) ? $data['text'] : '';
        $this->events[] = 'message:' . $text;
        $ack?->resolve(['ok' => true]);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function room(array $params): string
    {
        return (string) ($params['room'] ?? '');
    }
}
