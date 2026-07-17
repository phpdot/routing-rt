<?php

declare(strict_types=1);

/**
 * WsRoute — a registered WebSocket channel route. Wraps the underlying routing
 * Route (which does the path-pattern matching + params + where) and holds the
 * WebSocket middleware separately, since the routing Route only accepts PSR-15
 * middleware. Returned by RouterRT::ws('/chat/{room}', …).
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */

namespace PHPdot\Routing\RouterRT;

use PHPdot\Routing\Route\Route;

final class WsRoute
{
    /**
     * @var list<class-string> WebSocketMiddleware classes, run before the controller.
     */
    private array $middlewares = [];

    /**
     * __construct.
     *
     * @param Route $route
     */
    public function __construct(
        private readonly Route $route,
    ) {}

    /**
     * Add WebSocket middleware (runs before the controller method).
     *
     * @param class-string ...$middlewares Classes implementing WebSocketMiddleware.
     *
     * @return self
     */
    public function middleware(string ...$middlewares): self
    {
        foreach ($middlewares as $middleware) {
            $this->middlewares[] = $middleware;
        }

        return $this;
    }

    /**
     * Constrain a route param (delegates to the underlying route).
     *
     * @param string $key
     * @param string $pattern
     *
     * @return WsRoute
     */
    public function where(string $key, string $pattern): self
    {
        $this->route->where($key, $pattern);

        return $this;
    }

    /**
     * Name the route (delegates to the underlying route).
     *
     * @param string $name
     *
     * @return WsRoute
     */
    public function name(string $name): self
    {
        $this->route->name($name);

        return $this;
    }

    /**
     * The WebSocket middleware stack registered for this route.
     *
     * @return list<class-string>
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * The route parameter constraints (where clauses) for this route.
     *
     * @return array<string, string>
     */
    public function getWhere(): array
    {
        return $this->route->getWhere();
    }
}
