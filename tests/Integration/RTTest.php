<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPdot\Routing\Matcher\RouteMatch;
use PHPdot\Routing\RouterRT\RouterRT;
use PHPdot\Routing\RouterRT\Tests\Stubs\ChatControllerStub;
use PHPdot\Routing\RouterRT\Tests\Stubs\FeedControllerStub;
use PHPdot\Routing\RouterRT\Tests\Stubs\NotAControllerStub;
use PHPdot\Routing\RouterRT\Tests\Stubs\StubContainer;
use PHPdot\Routing\RouterRT\Tests\Stubs\StubMiddleware;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

final class RTTest extends TestCase
{
    private RouterRT $rt;
    private StubContainer $container;

    protected function setUp(): void
    {
        $this->container = new StubContainer();
        $factory = new Psr17Factory();
        $this->rt = new RouterRT($this->container, $factory);
    }

    #[Test]
    public function handleWsOpenReturnsTrueOnMatch(): void
    {
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $accepted = $this->rt->handleWsOpen(
            42,
            $this->request('/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        self::assertTrue($accepted);
    }

    #[Test]
    public function handleWsOpenReturnsFalseOnNoMatch(): void
    {
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $accepted = $this->rt->handleWsOpen(
            42,
            $this->request('/unknown'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        self::assertFalse($accepted);
    }

    #[Test]
    public function handleWsOpenCallsOnOpen(): void
    {
        $stub = new ChatControllerStub();
        $this->container->set(ChatControllerStub::class, $stub);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $this->rt->handleWsOpen(
            1,
            $this->request('/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        self::assertSame(['open'], $stub->events);
    }

    #[Test]
    public function handleWsMessageDispatchesToController(): void
    {
        $stub = new ChatControllerStub();
        $this->container->set(ChatControllerStub::class, $stub);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $this->rt->handleWsOpen(
            1,
            $this->request('/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        $this->rt->handleWsMessage(1, 'hello', 1);

        self::assertSame(['open', 'message:hello'], $stub->events);
    }

    #[Test]
    public function handleWsMessageIgnoresUnknownFd(): void
    {
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $this->rt->handleWsMessage(999, 'hello', 1);

        self::assertTrue(true);
    }

    #[Test]
    public function handleWsCloseCallsOnCloseAndCleansUp(): void
    {
        $stub = new ChatControllerStub();
        $this->container->set(ChatControllerStub::class, $stub);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $this->rt->handleWsOpen(
            1,
            $this->request('/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        $this->rt->handleWsClose(1, 1001, 'going away');
        $this->rt->handleWsMessage(1, 'after close', 1);

        self::assertSame(['open', 'close:1001:going away'], $stub->events);
    }

    #[Test]
    public function handleWsCloseIgnoresUnknownFd(): void
    {
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $this->rt->handleWsClose(999, 1000, '');

        self::assertTrue(true);
    }

    #[Test]
    public function handleWsOpenThrowsForInvalidController(): void
    {
        $this->rt->ws('/chat/{room}', NotAControllerStub::class);

        $this->expectException(RuntimeException::class);

        $this->rt->handleWsOpen(
            1,
            $this->request('/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );
    }

    #[Test]
    public function handleSseReturnsTrueOnMatch(): void
    {
        $this->rt->sse('/feed', FeedControllerStub::class);
        $output = '';

        $handled = $this->rt->handleSse(
            $this->sseRequest('/feed'),
            function (string $data) use (&$output): bool {
                $output .= $data;
                return true;
            },
            fn(): null => null,
        );

        self::assertTrue($handled);
        self::assertStringContainsString('event: ping', $output);
    }

    #[Test]
    public function handleSseReturnsFalseOnNoMatch(): void
    {
        $this->rt->sse('/feed', FeedControllerStub::class);

        $handled = $this->rt->handleSse(
            $this->sseRequest('/unknown'),
            fn(string $d): bool => true,
            fn(): null => null,
        );

        self::assertFalse($handled);
    }

    #[Test]
    public function handleSseReturnsFalseWithoutAcceptHeader(): void
    {
        $this->rt->sse('/feed', FeedControllerStub::class);

        $handled = $this->rt->handleSse(
            $this->request('/feed'),
            fn(string $d): bool => true,
            fn(): null => null,
        );

        self::assertFalse($handled);
    }

    #[Test]
    public function handleSseThrowsForInvalidController(): void
    {
        $this->rt->sse('/feed', NotAControllerStub::class);

        $this->expectException(RuntimeException::class);

        $this->rt->handleSse(
            $this->sseRequest('/feed'),
            fn(string $d): bool => true,
            fn(): null => null,
        );
    }

    #[Test]
    public function samePathHttpAndWsDoNotCollide(): void
    {
        $this->rt->get('/chat/{room}', ['HttpChatController', 'index']);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        $this->rt->compile();

        $httpMatch = $this->rt->match('GET', ['chat', 'general']);

        $wsAccepted = $this->rt->handleWsOpen(
            1,
            $this->request('/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        self::assertInstanceOf(RouteMatch::class, $httpMatch);
        self::assertTrue($wsAccepted);
    }

    #[Test]
    public function compileCompilesBothHttpAndRt(): void
    {
        $this->rt->get('/users', ['UserController', 'index']);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        $this->rt->compile();

        $httpMatch = $this->rt->match('GET', ['users']);

        $wsAccepted = $this->rt->handleWsOpen(
            1,
            $this->request('/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        self::assertInstanceOf(RouteMatch::class, $httpMatch);
        self::assertTrue($wsAccepted);
    }

    #[Test]
    public function listMergesHttpAndRtRoutes(): void
    {
        $this->rt->get('/users', ['UserController', 'index']);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        $this->rt->sse('/feed', FeedControllerStub::class);

        $list = $this->rt->list();

        self::assertCount(3, $list);
        self::assertSame(['GET'], $list[0]['methods']);
        self::assertSame(['WS'], $list[1]['methods']);
        self::assertSame(['SSE'], $list[2]['methods']);
    }

    #[Test]
    public function exposedMergesHttpAndRtRoutes(): void
    {
        $this->rt->get('/users', ['UserController', 'index'])->name('users.index')->expose();
        $this->rt->ws('/chat/{room}', ChatControllerStub::class)->name('ws.chat')->expose();

        $exposed = $this->rt->exposed();

        self::assertArrayHasKey('users.index', $exposed);
        self::assertArrayHasKey('ws.chat', $exposed);
    }

    #[Test]
    public function wsRouteSupportsName(): void
    {
        $route = $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        $route->name('ws.chat');

        self::assertSame('ws.chat', $route->getName());
    }

    #[Test]
    public function wsRouteSupportsMiddleware(): void
    {
        $route = $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        $route->middleware(StubMiddleware::class);

        self::assertContains(StubMiddleware::class, $route->getMiddlewares());
    }

    #[Test]
    public function sseRouteSupportsWhereConstraint(): void
    {
        $route = $this->rt->sse('/events/{type}', FeedControllerStub::class);
        $route->where('type', '[a-z]+');

        self::assertSame(['type' => '[a-z]+'], $route->getWhere());
    }

    #[Test]
    public function wsPrefixIsApplied(): void
    {
        $this->rt->group('/api', function ($group): void {
            $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        });

        $accepted = $this->rt->handleWsOpen(
            1,
            $this->request('/api/chat/general'),
            fn(string $d): bool => true,
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        self::assertTrue($accepted);
    }

    #[Test]
    public function wsRouteParamsPassedToConnection(): void
    {
        /** @var list<string> $sent */
        $sent = [];
        $stub = new ChatControllerStub();
        $this->container->set(ChatControllerStub::class, $stub);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        $this->rt->handleWsOpen(
            1,
            $this->request('/chat/general'),
            function (string $d) use (&$sent): bool {
                $sent[] = $d;
                return true;
            },
            fn(string $d): bool => true,
            fn(int $c, string $r): bool => true,
        );

        self::assertSame(['open'], $stub->events);
    }

    private function request(string $path): ServerRequestInterface
    {
        return new ServerRequest('GET', $path, ['Host' => 'localhost']);
    }

    private function sseRequest(string $path): ServerRequestInterface
    {
        return (new ServerRequest('GET', $path, ['Host' => 'localhost']))
            ->withHeader('Accept', 'text/event-stream');
    }
}
