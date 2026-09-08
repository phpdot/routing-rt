<?php

declare(strict_types=1);

/**
 * WebSocket-over-HTTP runner: a REAL phpdot Server with a WebSocket master,
 * driven through the ADAPTER a consumer of RouterRT must write — RouterRT does
 * not implement WebSocketHandlerInterface by design, so this fixture composes
 * it, and in composing it pays the two documented traps on purpose:
 *
 *   - handleWsOpen calls Hub::handleOpen() FIRST (dispatchWsOpen requires the
 *     socket to already exist — the asymmetric handshake);
 *   - handleWsMessage calls Hub::touch() (RouterRT never refreshes the Hub's
 *     liveness clock; without this, a chatty client is reaped as idle).
 *
 * One channel route behind one WebSocketMiddleware; the scenario argument says
 * whether the middleware proceeds or refuses. Launched as a separate process
 * by the WsHarness tests; argv is port, scenario.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Realtime\Adapter\TableAdapter;
use PHPdot\Realtime\Hub;
use PHPdot\Realtime\Socket;
use PHPdot\Routing\RouterRT\Bridge\WsServerAdapter;
use PHPdot\Routing\RouterRT\Channel\Ack;
use PHPdot\Routing\RouterRT\Contract\ChannelController;
use PHPdot\Routing\RouterRT\Contract\WebSocketMiddleware;
use PHPdot\Routing\RouterRT\Router\RouterRT;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Connection\ConnectionRegistry;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$refuses = ($argv[2] ?? 'proceed') === 'refuse';

if ($port <= 0) {
    fwrite(STDERR, "usage: ws_runner.php <port> <proceed|refuse>\n");
    exit(1);
}

$container = new class implements Psr\Container\ContainerInterface {
    /** @var array<string, object> */
    public array $services = [];

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new RuntimeException("Service '{$id}' not found.");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
};

$gate = new class ($refuses) implements WebSocketMiddleware {
    public function __construct(private readonly bool $refuses) {}

    public function process(Socket $socket, string $event, array $params, array $data, Ack|null $ack, Closure $next): void
    {
        if ($this->refuses) {
            return;
        }

        $next();
    }
};

$chat = new class implements ChannelController {
    public function subscribe(Socket $socket, array $params): void {}

    public function unsubscribe(Socket $socket, array $params): void {}

    public function onMessage(Socket $socket, array $params, array $payload, Ack|null $ack): void
    {
        $socket->emit('echo', ['room' => $params['room'] ?? '', 'text' => $payload['text'] ?? '']);

        $ack?->resolve(['ok' => true]);
    }
};

$container->services[$gate::class] = $gate;
$container->services[$chat::class] = $chat;

/*
 * The canonical wiring, exactly as the shipped adapter's docblock draws it:
 * the registry is the Hub's outbound seam, and WsServerAdapter is the one
 * handler served — HTTP, SSE, and WebSocket channels behind it. The wire
 * tests therefore exercise the SHIPPED class, not a fixture copy of it.
 */
$server = new Server(new ServerConfig(workerNum: 1, hookFlags: SWOOLE_HOOK_ALL));
$server->attach(new HttpServer(new ResponseFactory(), new HttpServerConfig(host: '127.0.0.1', port: $port)));

$registry = new ConnectionRegistry($server);

$hub = new Hub(new TableAdapter($registry), $registry);

$router = new RouterRT($container, new ResponseFactory(), $hub);
$router->ws('/chat/{room}', $chat::class)->middleware($gate::class);

$server->serve(new WsServerAdapter($router, $hub));
