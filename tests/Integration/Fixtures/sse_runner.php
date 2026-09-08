<?php

declare(strict_types=1);

/**
 * SSE-over-HTTP runner: a REAL phpdot Server whose serve-handler is the
 * RouterRT itself — RequestHandlerInterface through the parent for HTTP,
 * SseHandlerInterface natively, so the server's SSE branch fires exactly as it
 * does in production. One SSE route behind one PSR-15 middleware; the scenario
 * argument says whether the middleware proceeds or refuses.
 *
 * Launched as a separate process by the SseHarness tests; argv is port, scenario.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Realtime\Adapter\TableAdapter;
use PHPdot\Realtime\Hub;
use PHPdot\Routing\RouterRT\Contract\SSEController;
use PHPdot\Routing\RouterRT\Router\RouterRT;
use PHPdot\Routing\RouterRT\Tests\Stubs\FakeSender;
use PHPdot\Routing\RouterRT\Transport\SSEWriter;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use RuntimeException;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$refuses = ($argv[2] ?? 'proceed') === 'refuse';

if ($port <= 0) {
    fwrite(STDERR, "usage: sse_runner.php <port> <proceed|refuse>\n");
    exit(1);
}

$sender = new FakeSender();
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

$gate = new class ($refuses, new ResponseFactory()) implements MiddlewareInterface {
    public function __construct(
        private readonly bool $refuses,
        private readonly ResponseFactory $responses,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($this->refuses) {
            return $this->responses->createResponse(401);
        }

        return $handler->handle($request);
    }
};

$feed = new class implements SSEController {
    public function stream(SSEWriter $writer): void
    {
        $writer->event('tick', '1');
        $writer->event('tick', '2');
        $writer->event('done', 'ok');
    }
};

$container->services[$gate::class] = $gate;
$container->services[$feed::class] = $feed;

$router = new RouterRT($container, new ResponseFactory(), new Hub(new TableAdapter($sender), $sender));
$router->sse('/feed', $feed::class)->middleware($gate::class);

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: SWOOLE_HOOK_ALL));
$server->attach(new HttpServer(new ResponseFactory(), new HttpServerConfig(host: '127.0.0.1', port: $port)));
$server->serve($router);
