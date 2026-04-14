<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPdot\Routing\Matcher\RouteMatch;
use PHPdot\Routing\RouterRT\RouterRT;
use PHPdot\Routing\RouterRT\Tests\Stubs\StubContainer;
use PHPdot\Routing\RouterRT\Tests\Stubs\StubMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

final class RTTest extends TestCase
{
    private RouterRT $rt;

    protected function setUp(): void
    {
        $container = new StubContainer();
        $factory = new Psr17Factory();
        $this->rt = new RouterRT($container, $factory);
    }

    // ── WebSocket matching ──

    #[Test]
    public function matchesWebSocketRoute(): void
    {
        $this->rt->ws('/chat/{room}', ['ChatController', 'index']);
        $this->rt->compileRt();

        $match = $this->rt->matchRt($this->wsRequest('/chat/general'));

        self::assertInstanceOf(RouteMatch::class, $match);
        self::assertSame(['room' => 'general'], $match->getParameters());
    }

    #[Test]
    public function returnsNullForUnmatchedWebSocket(): void
    {
        $this->rt->ws('/chat/{room}', ['ChatController', 'index']);
        $this->rt->compileRt();

        $match = $this->rt->matchRt($this->wsRequest('/unknown'));

        self::assertNull($match);
    }

    #[Test]
    public function doesNotMatchWebSocketWithoutUpgradeHeader(): void
    {
        $this->rt->ws('/chat/{room}', ['ChatController', 'index']);
        $this->rt->compileRt();

        $match = $this->rt->matchRt(new ServerRequest('GET', '/chat/general'));

        self::assertNull($match);
    }

    // ── SSE matching ──

    #[Test]
    public function matchesSseRoute(): void
    {
        $this->rt->sse('/dashboard/{id:int}', ['DashboardController', 'stream']);
        $this->rt->compileRt();

        $match = $this->rt->matchRt($this->sseRequest('/dashboard/42'));

        self::assertInstanceOf(RouteMatch::class, $match);
        self::assertSame(['id' => 42], $match->getParameters());
    }

    #[Test]
    public function returnsNullForUnmatchedSse(): void
    {
        $this->rt->sse('/dashboard/{id:int}', ['DashboardController', 'stream']);
        $this->rt->compileRt();

        $match = $this->rt->matchRt($this->sseRequest('/unknown'));

        self::assertNull($match);
    }

    #[Test]
    public function doesNotMatchSseWithoutAcceptHeader(): void
    {
        $this->rt->sse('/dashboard/{id:int}', ['DashboardController', 'stream']);
        $this->rt->compileRt();

        $match = $this->rt->matchRt(new ServerRequest('GET', '/dashboard/42'));

        self::assertNull($match);
    }

    // ── Isolation from HTTP routes ──

    #[Test]
    public function samePath_httpAndWs_doNotCollide(): void
    {
        $this->rt->get('/chat/{room}', ['HttpChatController', 'index']);
        $this->rt->ws('/chat/{room}', ['WsChatController', 'index']);
        $this->rt->compile();
        $this->rt->compileRt();

        $httpMatch = $this->rt->match('GET', ['chat', 'general']);
        $wsMatch = $this->rt->matchRt($this->wsRequest('/chat/general'));

        self::assertInstanceOf(RouteMatch::class, $httpMatch);
        self::assertInstanceOf(RouteMatch::class, $wsMatch);
        self::assertSame(['HttpChatController', 'index'], $httpMatch->getRoute()->getHandler());
        self::assertSame(['WsChatController', 'index'], $wsMatch->getRoute()->getHandler());
    }

    #[Test]
    public function samePath_httpAndSse_doNotCollide(): void
    {
        $this->rt->get('/dashboard/{id:int}', ['HttpDashController', 'show']);
        $this->rt->sse('/dashboard/{id:int}', ['SseDashController', 'stream']);
        $this->rt->compile();
        $this->rt->compileRt();

        $httpMatch = $this->rt->match('GET', ['dashboard', '5']);
        $sseMatch = $this->rt->matchRt($this->sseRequest('/dashboard/5'));

        self::assertInstanceOf(RouteMatch::class, $httpMatch);
        self::assertInstanceOf(RouteMatch::class, $sseMatch);
        self::assertSame(['HttpDashController', 'show'], $httpMatch->getRoute()->getHandler());
        self::assertSame(['SseDashController', 'stream'], $sseMatch->getRoute()->getHandler());
    }

    // ── Route features ──

    #[Test]
    public function wsRouteSupportsName(): void
    {
        $route = $this->rt->ws('/chat/{room}', ['ChatController', 'index']);
        $route->name('ws.chat');

        self::assertSame('ws.chat', $route->getName());
    }

    #[Test]
    public function wsRouteSupportsMiddleware(): void
    {
        $route = $this->rt->ws('/chat/{room}', ['ChatController', 'index']);
        $route->middleware(StubMiddleware::class);

        self::assertContains(StubMiddleware::class, $route->getMiddlewares());
    }

    #[Test]
    public function sseRouteSupportsWhereConstraint(): void
    {
        $route = $this->rt->sse('/events/{type}', ['EventController', 'stream']);
        $route->where('type', '[a-z]+');

        self::assertSame(['type' => '[a-z]+'], $route->getWhere());
    }

    // ── Prefix support ──

    #[Test]
    public function wsPrefixIsApplied(): void
    {
        $this->rt->group('/api', function ($group) {
            $this->rt->ws('/chat/{room}', ['ChatController', 'index']);
        });
        $this->rt->compileRt();

        $match = $this->rt->matchRt($this->wsRequest('/api/chat/general'));

        self::assertInstanceOf(RouteMatch::class, $match);
        self::assertSame(['room' => 'general'], $match->getParameters());
    }

    // ── Auto-compile ──

    #[Test]
    public function autoCompilesOnFirstMatch(): void
    {
        $this->rt->ws('/chat/{room}', ['ChatController', 'index']);

        $match = $this->rt->matchRt($this->wsRequest('/chat/general'));

        self::assertInstanceOf(RouteMatch::class, $match);
    }

    private function wsRequest(string $path): ServerRequestInterface
    {
        return (new ServerRequest('GET', $path, ['Host' => 'localhost']))
            ->withHeader('Upgrade', 'websocket')
            ->withHeader('Connection', 'Upgrade');
    }

    private function sseRequest(string $path): ServerRequestInterface
    {
        return (new ServerRequest('GET', $path, ['Host' => 'localhost']))
            ->withHeader('Accept', 'text/event-stream');
    }
}
