<?php

declare(strict_types=1);

/**
 * RouterRT — extends the HTTP Router with real-time routing. WebSocket CHANNELS
 * and SSE are registered as PATH-pattern routes (ws('/chat/{room}', ...),
 * sse('/feed', ...)) matched by the SAME trie as HTTP — named params, where(),
 * middleware. A WebSocket's CONNECTION URL is its channel: dispatchWsOpen matches
 * the request path, binds the resolved channel to the fd, runs middleware ONCE
 * (per-connection, like HTTP), then subscribes; dispatchWsMessage dispatches
 * {event, data} frames to that bound controller (the channel is NOT in the
 * payload — event 'message' → onMessage); dispatchWsClose unsubscribes. The Hub
 * provides rooms/presence/broadcast beneath.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Routing\RouterRT;

use Closure;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Server\SseHandlerInterface;
use PHPdot\Realtime\Event;
use PHPdot\Realtime\Hub;
use PHPdot\Realtime\Socket;
use PHPdot\Routing\Compiler\RouteCompiler;
use PHPdot\Routing\Contract\MatcherInterface;
use PHPdot\Routing\Matcher\RouteMatch;
use PHPdot\Routing\Matcher\TrieMatcher;
use PHPdot\Routing\Route\Route;
use PHPdot\Routing\Route\RouteCollection;
use PHPdot\Routing\Router;
use PHPdot\Routing\RouterRT\Contract\ChannelController;
use PHPdot\Routing\RouterRT\Contract\SSEController;
use PHPdot\Routing\RouterRT\Contract\WebSocketMiddleware;
use PHPdot\Routing\Utils\Path;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[Singleton]
final class RouterRT extends Router implements SseHandlerInterface
{
    private RouteCollection $rtRoutes;
    private MatcherInterface|null $rtMatcher = null;

    /**
     * @var array<string, WsRoute> WS route pattern → wrapper (holds WS middleware).
     */
    private array $wsRoutes = [];

    /**
     * fd → the channel bound to that connection at open. Per-worker: a WS fd is
     * owned by the worker that accepted it, and all of its open/message/close
     * events dispatch to that same worker (same locality as Hub::$sockets).
     *
     * @var array<int, array{route: Route, controller: ChannelController, params: array<string, int|string>}>
     */
    private array $wsBindings = [];

    /**
     * __construct.
     *
     * @param ContainerInterface $container
     * @param ResponseFactoryInterface $responseFactory
     * @param Hub $hub
     */
    public function __construct(
        private readonly ContainerInterface $container,
        ResponseFactoryInterface $responseFactory,
        private readonly Hub $hub,
    ) {
        parent::__construct($container, $responseFactory);
        $this->rtRoutes = new RouteCollection();
    }

    /**
     * Register a WebSocket channel route — a PATH pattern matched against the
     * WebSocket CONNECTION URL (ws://host/chat/general → '/chat/{room}'), exactly
     * like an HTTP/SSE route. Supports named params, where(), name(), and
     * middleware() (WebSocketMiddleware, run once at open):
     *
     *   $router->ws('/chat/{room}', ChatController::class)->middleware(Auth::class);
     *   $router->ws('/prices/{symbols}', StockController::class);
     *
     * @param string $pattern Channel path pattern (e.g. '/chat/{room}').
     * @param class-string $controller Class implementing ChannelController.
     *
     * @return WsRoute
     */
    public function ws(string $pattern, string $controller): WsRoute
    {
        $route = $this->addRtRoute('WS', $pattern, $controller);
        $wsRoute = new WsRoute($route);
        $this->wsRoutes[$route->getPattern()] = $wsRoute;

        return $wsRoute;
    }

    /**
     * WebSocket OPEN — the connection URL IS the channel. Match the request path
     * against the WS routes (named params), bind the resolved channel to this fd,
     * run the route middleware, then subscribe. Returns false if no channel matches
     * or a middleware rejects (never calls next()) — the transport then disconnects
     * the fd. This is the per-connection auth gate: a rejected open is a closed socket.
     *
     * @param int $fd
     * @param ServerRequestInterface $request
     *
     * @return bool
     */
    public function dispatchWsOpen(int $fd, ServerRequestInterface $request): bool
    {
        $socket = $this->hub->socket($fd);

        if ($socket === null) {
            return false;
        }

        $match = $this->matchChannel($request->getUri()->getPath());

        if ($match === null) {
            return false;
        }

        $controller = $this->container->get($this->resolveHandlerClass($match->getRoute()->getHandler()));

        if (!$controller instanceof ChannelController) {
            return false;
        }

        $params = $this->normaliseParams($match->getParameters());
        $this->wsBindings[$fd] = ['route' => $match->getRoute(), 'controller' => $controller, 'params' => $params];

        $subscribed = false;
        $subscribe = static function () use ($controller, $socket, $params, &$subscribed): void {
            $controller->subscribe($socket, $params);
            $subscribed = true;
        };

        $this->runMiddleware($match->getRoute(), $socket, 'subscribe', $params, [], null, $subscribe);

        if (!$subscribed) {
            unset($this->wsBindings[$fd]);

            return false;
        }

        return true;
    }

    /**
     * WebSocket MESSAGE — dispatch a {event, data, ack} frame to the fd's BOUND
     * channel controller (event 'message' → onMessage, 'order.create' → onOrderCreate).
     * The channel is NOT in the payload; it was fixed at open. subscribe/unsubscribe
     * are lifecycle (open/close) and 'ack' is a client reply — all reserved/ignored.
     *
     * @param string $data
     * @param int $fd
     *
     * @return void
     */
    public function dispatchWsMessage(int $fd, string $data): void
    {
        $socket = $this->hub->socket($fd);
        $binding = $this->wsBindings[$fd] ?? null;

        if ($socket === null || $binding === null) {
            return;
        }

        $decoded = Event::decode($data);

        if ($decoded === null) {
            return;
        }

        $event = $decoded['event'];

        if ($event === 'subscribe' || $event === 'unsubscribe' || $event === 'ack') {
            return;
        }

        $payload = is_array($decoded['data']) ? $decoded['data'] : [];
        $ack = $decoded['ack'] !== null ? new Ack($decoded['ack'], $socket) : null;

        $handler = $this->resolveEventHandler($binding['controller'], $event, $socket, $binding['params'], $payload, $ack);

        if ($handler !== null) {
            $handler();
        }
    }

    /**
     * WebSocket CLOSE — unsubscribe the fd's bound controller (leaving rooms →
     * presence:left), then hand off to the Hub for connection cleanup. Unsubscribe
     * runs WITHOUT middleware: teardown must not depend on auth that may already be gone.
     *
     * @param int $fd
     *
     * @return void
     */
    public function dispatchWsClose(int $fd): void
    {
        $binding = $this->wsBindings[$fd] ?? null;
        $socket = $this->hub->socket($fd);

        if ($binding !== null && $socket !== null) {
            $binding['controller']->unsubscribe($socket, $binding['params']);
        }

        unset($this->wsBindings[$fd]);
        $this->hub->handleClose($fd);
    }

    /**
     * Match a channel path (the WS connection URL) against the WS route patterns.
     *
     * @param string $channel
     *
     * @return ?RouteMatch
     */
    private function matchChannel(string $channel): RouteMatch|null
    {
        if ($this->rtMatcher === null) {
            $this->compileRtRoutes();
        }

        assert($this->rtMatcher instanceof MatcherInterface);

        $result = $this->rtMatcher->match('WS', Path::segments($channel), '');

        return $result instanceof RouteMatch ? $result : null;
    }

    /**
     * Run the route's WebSocket middleware around $core (the subscribe call). A
     * middleware that never calls next() short-circuits — $core never runs, which is
     * how open-time middleware rejects a connection.
     *
     * @param array<string, int|string> $params
     * @param array<mixed, mixed> $payload
     * @param Route $route
     * @param Socket $socket
     * @param string $event
     * @param ?Ack $ack
     * @param Closure $core
     *
     * @return void
     */
    private function runMiddleware(Route $route, Socket $socket, string $event, array $params, array $payload, Ack|null $ack, Closure $core): void
    {
        $chain = $core;
        $middlewares = ($this->wsRoutes[$route->getPattern()] ?? null)?->getMiddlewares() ?? [];

        foreach (array_reverse($middlewares) as $middlewareClass) {
            $middleware = $this->container->get($middlewareClass);

            if (!$middleware instanceof WebSocketMiddleware) {
                throw new RuntimeException("'{$middlewareClass}' must implement " . WebSocketMiddleware::class);
            }

            $next = $chain;
            $chain = static function () use ($middleware, $socket, $event, $params, $payload, $ack, $next): void {
                $middleware->process($socket, $event, $params, $payload, $ack, $next);
            };
        }

        $chain();
    }

    /**
     * Resolve a convention-dispatched event to a controller call (event 'message' →
     * onMessage). subscribe/unsubscribe are lifecycle, handled at open/close — not here.
     *
     * @param array<string, int|string> $params
     * @param array<mixed, mixed> $payload
     * @param object $controller
     * @param string $event
     * @param Socket $socket
     * @param ?Ack $ack
     *
     * @return (Closure(): void)|null
     */
    private function resolveEventHandler(object $controller, string $event, Socket $socket, array $params, array $payload, Ack|null $ack): ?Closure
    {
        $method = $this->eventToMethod($event);

        if (!is_callable([$controller, $method])) {
            return null;
        }

        $handler = Closure::fromCallable([$controller, $method]);

        return static function () use ($handler, $socket, $params, $payload, $ack): void {
            $handler($socket, $params, $payload, $ack);
        };
    }

    /**
     * Normalise router params (loosely typed mixed) to their guaranteed int|string.
     * Only {id:int} value-coerces to int (TrieMatcher::castParams); every other type —
     * string, slug, uuid, mongo_id, locale, wildcard, custom — is a regex constraint
     * whose value stays a string. So a param is exactly int|string.
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, int|string>
     */
    private function normaliseParams(array $params): array
    {
        return array_map(
            static fn(mixed $value): int|string => match (true) {
                is_int($value) => $value,
                is_string($value) => $value,
                default => '',
            },
            $params,
        );
    }

    /**
     * event 'message' → 'onMessage'; 'order.create' → 'onOrderCreate'.
     *
     * @param string $event
     *
     * @return string
     */
    private function eventToMethod(string $event): string
    {
        $method = 'on';

        foreach (explode('.', $event) as $part) {
            $method .= ucfirst($part);
        }

        return $method;
    }

    /**
     * Register an SSE route.
     *
     * @param string $handler Class name implementing SSEController
     * @param string $pattern
     *
     * @return Route
     */
    public function sse(string $pattern, string $handler): Route
    {
        return $this->addRtRoute('SSE', $pattern, $handler);
    }

    public function handleSse(
        ServerRequestInterface $request,
        Closure $write,
        Closure $close,
    ): bool {
        if (!str_contains($request->getHeaderLine('accept'), 'text/event-stream')) {
            return false;
        }

        $match = $this->matchRoute('SSE', $request);

        if ($match === null) {
            return false;
        }

        $class = $this->resolveHandlerClass($match->getRoute()->getHandler());
        $controller = $this->container->get($class);

        if (!$controller instanceof SSEController) {
            throw new RuntimeException(
                "Handler '{$class}' must implement " . SSEController::class,
            );
        }

        $lastEventId = $request->getHeaderLine('Last-Event-ID');
        $writer = new SSEWriter($write, $close, $lastEventId !== '' ? $lastEventId : null);
        $controller->stream($writer);
        $writer->markClosed();

        return true;
    }

    /**
     * Compile both HTTP and RT routes.
     */
    public function compile(): void
    {
        parent::compile();
        $this->compileRtRoutes();
    }

    /**
     * List all routes — HTTP and RT merged.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        return array_merge(parent::list(), $this->listRtRoutes());
    }

    /**
     * Get exposed routes — HTTP and RT merged.
     *
     * @return array<string, string>
     */
    public function exposed(): array
    {
        $map = parent::exposed();

        foreach ($this->rtRoutes->getExposed() as $route) {
            $name = $route->getName();
            if ($name !== null) {
                $map[$name] = '/' . ltrim($route->getPattern(), '/');
            }
        }

        return $map;
    }

    /**
     * Match route.
     *
     * @param string $type
     * @param ServerRequestInterface $request
     *
     * @return RouteMatch|null
     */
    private function matchRoute(string $type, ServerRequestInterface $request): RouteMatch|null
    {
        if ($this->rtMatcher === null) {
            $this->compileRtRoutes();
        }

        assert($this->rtMatcher instanceof MatcherInterface);

        $segments = Path::segments($request->getUri()->getPath());
        $host = $request->getHeaderLine('host');
        $result = $this->rtMatcher->match($type, $segments, $host);

        return $result instanceof RouteMatch ? $result : null;
    }

    /**
     * Resolve a route handler (string, [class, method], or closure) to its controller class name.
     *
     * @param Closure|string|array<int, string> $handler
     *
     * @return string
     */
    private function resolveHandlerClass(Closure|string|array $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_array($handler)) {
            return $handler[0];
        }

        throw new RuntimeException('WS/SSE handlers must be class names.');
    }

    /**
     * Add rt route.
     *
     * @param string $method
     * @param string $pattern
     * @param string $handler
     *
     * @return Route
     */
    private function addRtRoute(string $method, string $pattern, string $handler): Route
    {
        $fullPattern = $this->buildPattern($pattern);
        $segments = Path::segments($fullPattern);
        $route = new Route([$method], $fullPattern, $segments, $handler);
        $route->hosts($this->hosts);
        $this->rtRoutes->add($route);

        return $route;
    }

    /**
     * Compile rt routes.
     *
     * @return void
     */
    private function compileRtRoutes(): void
    {
        $compiler = new RouteCompiler($this->getPatterns());
        $root = $compiler->compile($this->rtRoutes);
        $this->rtMatcher = new TrieMatcher($root);
    }

    /**
     * List every registered real-time route as a plain array (for introspection).
     *
     * @return array<int, array<string, mixed>>
     */
    private function listRtRoutes(): array
    {
        $list = [];
        foreach ($this->rtRoutes->all() as $route) {
            $handler = $route->getHandler();
            if ($handler instanceof Closure) {
                $handlerString = 'Closure';
            } elseif (is_array($handler)) {
                $handlerString = $handler[0] . '@' . $handler[1];
            } else {
                $handlerString = $handler;
            }

            $list[] = [
                'methods' => $route->getMethods(),
                'pattern' => '/' . ltrim($route->getPattern(), '/'),
                'name' => $route->getName(),
                'handler' => $handlerString,
                'middlewares' => $route->getMiddlewares(),
                'hosts' => $route->getHosts(),
                'where' => $route->getWhere(),
                'scope' => $route->getScope()?->getName(),
            ];
        }

        return $list;
    }
}
