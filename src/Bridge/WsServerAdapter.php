<?php

declare(strict_types=1);

/**
 * Serves RouterRT on a real phpdot Server — the server-side WebSocket
 * contract translated onto the router's dispatch.
 *
 * RouterRT deliberately does not implement WebSocketHandlerInterface itself:
 * the server's callbacks carry the transport's send/close closures, which are
 * not the router's to own. This adapter is the composition instead — pass it
 * to Server::serve() and the WebSocket half of the router comes alive:
 *
 *     $registry = new ConnectionRegistry($server);
 *     $hub      = new Hub(new TableAdapter($registry), $registry);
 *     $router   = new RouterRT($container, $responseFactory, $hub);
 *     $router->ws('/chat/{room}', ChatController::class);
 *     $server->serve(new WsServerAdapter($router, $hub));
 *
 * The Hub's outbound frames travel the registry — the canonical seam the
 * server package ships for exactly this — so this class carries no bridge of
 * its own, only the lifecycle translation:
 *
 *   - OPEN calls Hub::handleOpen() BEFORE dispatchWsOpen(): the dispatch
 *     refuses an fd the Hub has no Socket for, so the Socket is created
 *     first (the asymmetric handshake — close mirrors it by letting
 *     dispatchWsClose clean the Hub up itself).
 *   - MESSAGE calls Hub::touch(): RouterRT never refreshes the Hub's
 *     liveness clock, and without this a continuously chatty client is
 *     reaped as idle.
 *
 * HTTP requests fall through to the router unchanged, so one handler serves
 * the whole surface: HTTP routes, SSE (the SseHandlerInterface branch), and
 * WebSocket channels.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Routing\RouterRT\Bridge;

use Closure;
use PHPdot\Contracts\Server\WebSocketHandlerInterface;
use PHPdot\Realtime\Hub;
use PHPdot\Routing\RouterRT\Router\RouterRT;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final readonly class WsServerAdapter implements WebSocketHandlerInterface, RequestHandlerInterface
{
    public function __construct(
        private RouterRT $router,
        private Hub $hub,
    ) {}

    /**
     * @inheritDoc
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->router->handle($request);
    }

    /**
     * @inheritDoc
     */
    public function handleWsOpen(
        int $fd,
        ServerRequestInterface $request,
        Closure $send,
        Closure $sendBinary,
        Closure $close,
    ): bool {
        $this->hub->handleOpen($fd, $request);

        return $this->router->dispatchWsOpen($fd, $request);
    }

    /**
     * @inheritDoc
     */
    public function handleWsMessage(int $fd, string $data, int $opcode): void
    {
        $this->hub->touch($fd);

        $this->router->dispatchWsMessage($fd, $data);
    }

    /**
     * @inheritDoc
     */
    public function handleWsClose(int $fd, int $code, string $reason): void
    {
        $this->router->dispatchWsClose($fd);
    }
}
