<?php

declare(strict_types=1);

/**
 * ONE NODE of a two-node realtime cluster: a real phpdot Server whose Hub
 * runs the RedisAdapter (rooms, presence, and cross-node relay in Redis),
 * the RedisSubscriber relay, and the membership maintenance — the production
 * multi-node shape, on one machine. TwoNodeClusterTest boots this twice on
 * different ports with different node ids and proves a broadcast crosses the
 * nodes and the cluster survives a peer's hard death.
 *
 * argv[1] = http port, argv[2] = node id (per-incarnation), argv[3] = redis port.
 */

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Realtime\Adapter\RedisAdapter;
use PHPdot\Realtime\Adapter\RedisSubscriber;
use PHPdot\Realtime\Bridge\DedicatedRedisSubscription;
use PHPdot\Realtime\Bridge\MaintenanceHalt;
use PHPdot\Realtime\Bridge\MaintenanceTick;
use PHPdot\Realtime\Bridge\RedisConnectionCommands;
use PHPdot\Realtime\Bridge\RelayHalt;
use PHPdot\Realtime\Bridge\RelayListener;
use PHPdot\Realtime\Hub;
use PHPdot\Realtime\Maintenance\ClusterMaintenance;
use PHPdot\Realtime\Socket;
use PHPdot\Redis\Config\RedisConfig;
use PHPdot\Redis\RedisConnection;
use PHPdot\Routing\RouterRT\Bridge\WsServerAdapter;
use PHPdot\Routing\RouterRT\Channel\Ack;
use PHPdot\Routing\RouterRT\Contract\ChannelController;
use PHPdot\Routing\RouterRT\Router\RouterRT;
use PHPdot\Server\Config\HttpServerConfig;
use PHPdot\Server\Config\ServerConfig;
use PHPdot\Server\Connection\ConnectionRegistry;
use PHPdot\Server\Http\HttpServer;
use PHPdot\Server\Server;
use Psr\Container\ContainerInterface;

$autoload = __DIR__;
while (!is_file($autoload . '/vendor/autoload.php') && dirname($autoload) !== $autoload) {
    $autoload = dirname($autoload);
}
require $autoload . '/vendor/autoload.php';

$port = (int) ($argv[1] ?? 0);
$nodeId = (string) ($argv[2] ?? '');
$redisPort = (int) ($argv[3] ?? 6380);

if ($port <= 0 || $nodeId === '') {
    fwrite(STDERR, "usage: cluster_node_runner.php <port> <nodeId> [redisPort]\n");
    exit(1);
}

$redisConfig = new RedisConfig(host: '127.0.0.1', port: $redisPort, timeout: 1.0, readTimeout: 1.0);

$server = new Server(new ServerConfig(workerNum: 1, hookFlags: SWOOLE_HOOK_ALL));
$server->attach(new HttpServer(new ResponseFactory(), new HttpServerConfig(host: '127.0.0.1', port: $port)));

$registry = new ConnectionRegistry($server);

/*
 * ONE prefix for the whole cluster — the prefix namespaces the Redis keys AND
 * the pub/sub channel, so two nodes with different prefixes are two clusters
 * that never hear each other.
 */
$adapter = new RedisAdapter(
    /*
     * A FRESH connection per command batch — the coroutine rule: one socket,
     * one coroutine. A memoized connection crashed exactly here (socket
     * already bound to another coroutine) the moment the maintenance beat and
     * a join overlapped; the bridge connects what it borrows.
     */
    connect: static fn(): RedisConnectionCommands => new RedisConnectionCommands(
        static fn(): RedisConnection => new RedisConnection($redisConfig),
    ),
    sender: $registry,
    nodeId: $nodeId,
    prefix: 'rt-test:cluster:' . (string) ($argv[4] ?? 'shared'),
);

$hub = new Hub($adapter, $registry);

$subscriber = new RedisSubscriber(
    adapter: $adapter,
    subscriptions: static fn(): DedicatedRedisSubscription => new DedicatedRedisSubscription(
        static fn(): RedisConnection => new RedisConnection($redisConfig),
    ),
    backoff: static function (): void {
        \Swoole\Coroutine::sleep(0.5);
    },
);

$maintenance = new ClusterMaintenance(
    $adapter,
    heartbeatIntervalMs: 1000,
    livenessTtlSeconds: 3,
    reapIntervalMs: 1000,
    reapLockTtlSeconds: 3,
);

$chat = new class implements ChannelController {
    public static Hub $hub;

    public function subscribe(Socket $socket, array $params): void
    {
        $socket->join('lobby');
    }

    public function unsubscribe(Socket $socket, array $params): void {}

    public function onMessage(Socket $socket, array $params, array $payload, Ack|null $ack): void
    {
        /*
         * To the ROOM, not the socket: the whole point is the other node's
         * subscriber receiving this and pushing to ITS connection.
         */
        self::$hub->to('lobby')->emit('said', ['text' => $payload['text'] ?? '']);

        $ack?->resolve(['ok' => true]);
    }
};

$chat::$hub = $hub;

$container = new class implements ContainerInterface {
    /** @var array<string, object> */
    public array $services = [];

    public function get(string $id): object
    {
        return $this->services[$id] ?? throw new \RuntimeException("Service '{$id}' not found.");
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]);
    }
};

$maintenanceTick = new MaintenanceTick($maintenance);

$container->services[$chat::class] = $chat;
$container->services[RedisSubscriber::class] = $subscriber;
$container->services[RelayListener::class] = new RelayListener($container);
$container->services[RelayHalt::class] = new RelayHalt($container);
$container->services[MaintenanceTick::class] = $maintenanceTick;
$container->services[MaintenanceHalt::class] = new MaintenanceHalt($maintenanceTick);

$router = new RouterRT($container, new ResponseFactory(), $hub);
$router->ws('/room/{name}', $chat::class);

/*
 * The #[ServerListener] classes dispatch through the ListenerBridge — events()
 * ->subscribe() alone only reaches On*Interface implementors, and a runner
 * without ServerFactory's discovery wires no bridge. One bridge, explicit
 * routes, every listener real.
 */
$routes = [];
$instances = [];
foreach ([new RelayListener($container), new RelayHalt($container), $maintenanceTick, new MaintenanceHalt($maintenanceTick)] as $listener) {
    $event = \PHPdot\Server\Listener\ListenerSignature::eventTypeOf($listener::class);

    if (is_string($event)) {
        $routes[$event][] = $listener::class;
        $instances[$listener::class] = $listener;
    }
}

$bridge = new ReflectionClass(\PHPdot\Server\Listener\ListenerBridge::class)->newInstanceArgs([$container, $routes]);

$server->events()->subscribe($bridge);

echo "READY {$nodeId}\n";

$server->serve(new WsServerAdapter($router, $hub));
