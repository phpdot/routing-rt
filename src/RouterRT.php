<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT;

use Closure;
use PHPdot\Container\Attribute\Singleton;
use PHPdot\Contracts\Server\SseHandlerInterface;
use PHPdot\Contracts\Server\WebSocketHandlerInterface;
use PHPdot\Routing\Compiler\RouteCompiler;
use PHPdot\Routing\Matcher\MatcherInterface;
use PHPdot\Routing\Matcher\RouteMatch;
use PHPdot\Routing\Matcher\TrieMatcher;
use PHPdot\Routing\Route\Route;
use PHPdot\Routing\Route\RouteCollection;
use PHPdot\Routing\Router;
use PHPdot\Routing\RouterRT\Contracts\SSEController;
use PHPdot\Routing\RouterRT\Contracts\WebSocketController;
use PHPdot\Routing\Utils\Path;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

#[Singleton]
final class RouterRT extends Router implements WebSocketHandlerInterface, SseHandlerInterface
{
    private RouteCollection $rtRoutes;
    private MatcherInterface|null $rtMatcher = null;

    /** @var array<int, array{conn: Connection, controller: WebSocketController}> */
    private array $connections = [];

    public function __construct(
        private readonly ContainerInterface $container,
        ResponseFactoryInterface $responseFactory,
    ) {
        parent::__construct($container, $responseFactory);
        $this->rtRoutes = new RouteCollection();
    }

    /**
     * Register a WebSocket route.
     *
     * @param string $handler Class name implementing WebSocketController
     */
    public function ws(string $pattern, string $handler): Route
    {
        return $this->addRtRoute('WS', $pattern, $handler);
    }

    /**
     * Register an SSE route.
     *
     * @param string $handler Class name implementing SSEController
     */
    public function sse(string $pattern, string $handler): Route
    {
        return $this->addRtRoute('SSE', $pattern, $handler);
    }

    public function handleWsOpen(
        int $fd,
        ServerRequestInterface $request,
        Closure $send,
        Closure $sendBinary,
        Closure $close,
    ): bool {
        $match = $this->matchRoute('WS', $request);

        if ($match === null) {
            return false;
        }

        $conn = new Connection($fd, $send, $sendBinary, $close, $request);
        $conn->setParams($match->getParameters());

        $class = $this->resolveHandlerClass($match->getRoute()->getHandler());
        $controller = $this->container->get($class);

        if (!$controller instanceof WebSocketController) {
            throw new RuntimeException(
                "Handler '{$class}' must implement " . WebSocketController::class,
            );
        }

        $this->connections[$fd] = ['conn' => $conn, 'controller' => $controller];
        $controller->onOpen($conn);

        return true;
    }

    public function handleWsMessage(int $fd, string $data, int $opcode): void
    {
        if (!isset($this->connections[$fd])) {
            return;
        }

        $entry = $this->connections[$fd];
        $entry['controller']->onMessage(
            $entry['conn'],
            new Frame($data, Opcode::from($opcode)),
        );
    }

    public function handleWsClose(int $fd, int $code, string $reason): void
    {
        if (!isset($this->connections[$fd])) {
            return;
        }

        $entry = $this->connections[$fd];
        $entry['controller']->onClose($entry['conn'], $code, $reason);
        unset($this->connections[$fd]);
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
     * @param Closure|string|array<int, string> $handler
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

    private function addRtRoute(string $method, string $pattern, string $handler): Route
    {
        $fullPattern = $this->buildPattern($pattern);
        $segments = Path::segments($fullPattern);
        $route = new Route([$method], $fullPattern, $segments, $handler);
        $route->hosts($this->hosts);
        $this->rtRoutes->add($route);

        return $route;
    }

    private function compileRtRoutes(): void
    {
        $compiler = new RouteCompiler($this->getPatterns());
        $root = $compiler->compile($this->rtRoutes);
        $this->rtMatcher = new TrieMatcher($root);
    }

    /**
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
