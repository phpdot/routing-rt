<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT;

use Closure;
use PHPdot\Routing\Compiler\RouteCompiler;
use PHPdot\Routing\Matcher\MatcherInterface;
use PHPdot\Routing\Matcher\RouteMatch;
use PHPdot\Routing\Matcher\TrieMatcher;
use PHPdot\Routing\Route\Route;
use PHPdot\Routing\Route\RouteCollection;
use PHPdot\Routing\Router;
use PHPdot\Routing\Utils\Path;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;

final class RouterRT extends Router
{
    private RouteCollection $rtRoutes;
    private MatcherInterface|null $rtMatcher = null;

    public function __construct(
        ContainerInterface $container,
        ResponseFactoryInterface $responseFactory,
    ) {
        parent::__construct($container, $responseFactory);
        $this->rtRoutes = new RouteCollection();
    }

    /**
     * Register a WebSocket route.
     *
     * @param Closure|string|array<int, string> $handler
     */
    public function ws(string $pattern, Closure|string|array $handler): Route
    {
        return $this->addRtRoute('WS', $pattern, $handler);
    }

    /**
     * Register an SSE route.
     *
     * @param Closure|string|array<int, string> $handler
     */
    public function sse(string $pattern, Closure|string|array $handler): Route
    {
        return $this->addRtRoute('SSE', $pattern, $handler);
    }

    /**
     * Match against RT routes using request headers.
     * Upgrade: websocket → WS routes. Accept: text/event-stream → SSE routes.
     * Returns null if no RT route matches — caller falls back to HTTP.
     */
    public function matchRt(ServerRequestInterface $request): RouteMatch|null
    {
        if ($this->rtMatcher === null) {
            $this->compileRt();
        }

        assert($this->rtMatcher instanceof MatcherInterface);

        $segments = Path::segments($request->getUri()->getPath());
        $host = $request->getHeaderLine('host');

        if (strtolower($request->getHeaderLine('upgrade')) === 'websocket') {
            $result = $this->rtMatcher->match('WS', $segments, $host);
            return $result instanceof RouteMatch ? $result : null;
        }

        if (str_contains($request->getHeaderLine('accept'), 'text/event-stream')) {
            $result = $this->rtMatcher->match('SSE', $segments, $host);
            return $result instanceof RouteMatch ? $result : null;
        }

        return null;
    }

    /**
     * Compile all routes — HTTP and RT.
     */
    public function compile(): void
    {
        parent::compile();
        $this->compileRt();
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
        $httpExposed = parent::exposed();
        $rtExposed = [];
        foreach ($this->rtRoutes->getExposed() as $route) {
            $name = $route->getName();
            if ($name !== null) {
                $rtExposed[$name] = '/' . ltrim($route->getPattern(), '/');
            }
        }

        return array_merge($httpExposed, $rtExposed);
    }

    /**
     * Get the RT route collection.
     */
    public function getRtRoutes(): RouteCollection
    {
        return $this->rtRoutes;
    }

    /**
     * Compile RT routes into a separate trie.
     */
    public function compileRt(): void
    {
        $compiler = new RouteCompiler($this->getPatterns());
        $root = $compiler->compile($this->rtRoutes);
        $this->rtMatcher = new TrieMatcher($root);
    }

    /**
     * @param Closure|string|array<int, string> $handler
     */
    private function addRtRoute(string $method, string $pattern, Closure|string|array $handler): Route
    {
        $fullPattern = $this->buildPattern($pattern);
        $segments = Path::segments($fullPattern);
        $route = new Route([$method], $fullPattern, $segments, $handler);
        $route->hosts($this->hosts);
        $this->rtRoutes->add($route);

        return $route;
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
