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

class RouterRT extends Router
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
     * Match a request against RT routes.
     *
     * Detects WebSocket via Upgrade header, SSE via Accept header.
     * Returns null if no RT route matches (caller should fall back to HTTP).
     */
    public function matchRt(ServerRequestInterface $request): RouteMatch|null
    {
        if ($this->rtMatcher === null) {
            $this->compileRt();
        }

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
     * Compile RT routes into a separate trie.
     */
    public function compileRt(): void
    {
        $compiler = new RouteCompiler($this->getPatterns());
        $root = $compiler->compile($this->rtRoutes);
        $this->rtMatcher = new TrieMatcher($root);
    }

    /**
     * Get the RT route collection.
     */
    public function getRtRoutes(): RouteCollection
    {
        return $this->rtRoutes;
    }

    private function addRtRoute(string $method, string $pattern, Closure|string|array $handler): Route
    {
        $fullPattern = $this->buildPattern($pattern);
        $segments = Path::segments($fullPattern);

        $route = new Route([$method], $fullPattern, $segments, $handler);
        $route->hosts($this->hosts);
        $this->rtRoutes->add($route);

        return $route;
    }
}
