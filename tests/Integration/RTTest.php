<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use PHPdot\Http\Factory\ResponseFactory;
use PHPdot\Http\Message\ServerRequest;
use PHPdot\Realtime\Adapter\TableAdapter;
use PHPdot\Realtime\Hub;
use PHPdot\Routing\Matcher\RouteMatch;
use PHPdot\Routing\RouterRT\RouterRT;
use PHPdot\Routing\RouterRT\Tests\Stubs\ChatControllerStub;
use PHPdot\Routing\RouterRT\Tests\Stubs\FakeSender;
use PHPdot\Routing\RouterRT\Tests\Stubs\FeedControllerStub;
use PHPdot\Routing\RouterRT\Tests\Stubs\NotAControllerStub;
use PHPdot\Routing\RouterRT\Tests\Stubs\RejectingWsMiddleware;
use PHPdot\Routing\RouterRT\Tests\Stubs\StubContainer;
use PHPdot\Routing\RouterRT\Tests\Stubs\StubWsMiddleware;
use PHPdot\Routing\RouterRT\Tests\Stubs\TypedParamControllerStub;
use PHPdot\Routing\RouterRT\WsRoute;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * RouterRT — HTTP + SSE + channel WebSocket routing. WS channels are PATH-pattern
 * routes (ws('/chat/{room}', …)) matched against the frame's `channel` field by
 * the same trie as HTTP/SSE, giving named params, where(), and middleware.
 */
final class RTTest extends TestCase
{
    private RouterRT $rt;

    private StubContainer $container;

    private Hub $hub;

    private FakeSender $sender;

    protected function setUp(): void
    {
        $this->container = new StubContainer();
        $this->sender = new FakeSender();
        $this->hub = new Hub(new TableAdapter($this->sender), $this->sender);
        $this->rt = new RouterRT($this->container, new ResponseFactory(), $this->hub);
    }

    // --- Channel registration (path patterns, like HTTP/SSE) ---

    #[Test]
    public function wsRegistersAPathPatternRoute(): void
    {
        $route = $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        self::assertInstanceOf(WsRoute::class, $route);
    }

    #[Test]
    public function wsRouteSupportsMiddlewareAndWhere(): void
    {
        $route = $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        $route->middleware(StubWsMiddleware::class)->where('room', '[a-z]+');

        self::assertContains(StubWsMiddleware::class, $route->getMiddlewares());
        self::assertSame(['room' => '[a-z]+'], $route->getWhere());
    }

    // --- Channel lifecycle: the connection URL IS the channel ---

    #[Test]
    public function openSubscribesWithNamedParamsFromTheUrl(): void
    {
        $stub = $this->registerChat();

        $accepted = $this->open(1, '/chat/general');

        self::assertTrue($accepted);
        self::assertSame(['subscribe:general'], $stub->events);
    }

    #[Test]
    public function messageDispatchesToConventionMethodOnBoundChannel(): void
    {
        $stub = $this->registerChat();
        $this->open(1, '/chat/general');

        // No channel in the payload — it was fixed at open.
        $this->rt->dispatchWsMessage(1, '{"event":"message","data":{"text":"hi"}}');

        self::assertSame(['subscribe:general', 'message:hi'], $stub->events);
    }

    #[Test]
    public function closeUnsubscribesTheBoundChannel(): void
    {
        $stub = $this->registerChat();
        $this->open(1, '/chat/general');

        $this->rt->dispatchWsClose(1);

        self::assertSame(['subscribe:general', 'unsubscribe:general'], $stub->events);
    }

    #[Test]
    public function openRunsMiddlewareOnceThenSubscribes(): void
    {
        $stub = new ChatControllerStub();
        $middleware = new StubWsMiddleware();
        $this->container->set(ChatControllerStub::class, $stub);
        $this->container->set(StubWsMiddleware::class, $middleware);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class)->middleware(StubWsMiddleware::class);

        $accepted = $this->open(1, '/chat/general');

        self::assertTrue($accepted);
        self::assertTrue($middleware->ran, 'middleware should run at open');
        self::assertSame(['subscribe:general'], $stub->events, 'controller should still run after middleware');
    }

    #[Test]
    public function middlewareThatDoesNotCallNextRejectsTheConnection(): void
    {
        $stub = new ChatControllerStub();
        $this->container->set(ChatControllerStub::class, $stub);
        $this->container->set(RejectingWsMiddleware::class, new RejectingWsMiddleware());
        $this->rt->ws('/chat/{room}', ChatControllerStub::class)->middleware(RejectingWsMiddleware::class);

        $accepted = $this->open(1, '/chat/general');

        self::assertFalse($accepted, 'rejecting middleware must reject the open (transport disconnects)');
        self::assertSame([], $stub->events, 'subscribe must not run when middleware rejects');
    }

    #[Test]
    public function messageResolvesAckThroughTheSocket(): void
    {
        $this->registerChat();
        $this->open(1, '/chat/general');
        $this->sender->sent[1] = [];

        $this->rt->dispatchWsMessage(1, '{"event":"message","data":{"text":"hi"},"ack":5}');

        $frames = $this->sender->sent[1] ?? [];
        self::assertNotEmpty($frames);
        $ackFrame = implode('', $frames);
        self::assertStringContainsString('"event":"ack"', $ackFrame);
        self::assertStringContainsString('"ack":5', $ackFrame);
    }

    #[Test]
    public function openRejectsUnknownChannel(): void
    {
        $stub = $this->registerChat();

        $accepted = $this->open(1, '/nope/x');

        self::assertFalse($accepted, 'an unrouted URL must be rejected');
        self::assertSame([], $stub->events);
    }

    #[Test]
    public function messageIgnoredForUnboundFd(): void
    {
        $stub = $this->registerChat();

        // No open() → no binding for this fd.
        $this->rt->dispatchWsMessage(999, '{"event":"message","data":{"text":"x"}}');

        self::assertSame([], $stub->events);
    }

    #[Test]
    public function messageIgnoresMalformedFrames(): void
    {
        $stub = $this->registerChat();
        $this->open(1, '/chat/general');

        $this->rt->dispatchWsMessage(1, 'not json');
        $this->rt->dispatchWsMessage(1, '{"no":"event"}');

        self::assertSame(['subscribe:general'], $stub->events);
    }

    #[Test]
    public function messageIgnoresReservedLifecycleEvents(): void
    {
        $stub = $this->registerChat();
        $this->open(1, '/chat/general');

        // subscribe/unsubscribe are lifecycle (open/close); 'ack' is a client reply.
        $this->rt->dispatchWsMessage(1, '{"event":"ack","data":{"ack":1}}');
        $this->rt->dispatchWsMessage(1, '{"event":"subscribe","data":{}}');
        $this->rt->dispatchWsMessage(1, '{"event":"unsubscribe","data":{}}');

        self::assertSame(['subscribe:general'], $stub->events);
    }

    #[Test]
    public function openCoercesIntParamLikeHttp(): void
    {
        // WS uses the same TrieMatcher as HTTP, so {id:int} value-coerces to int.
        $stub = new TypedParamControllerStub();
        $this->container->set(TypedParamControllerStub::class, $stub);
        $this->rt->ws('/orders/{id:int}', TypedParamControllerStub::class);

        $this->open(1, '/orders/42');

        self::assertSame(42, $stub->id, 'int param should arrive as int, not "42"');
        self::assertSame('int', $stub->idType);
    }

    #[Test]
    public function openKeepsNonIntParamAsString(): void
    {
        // Every other type (slug, uuid, default) is a regex constraint — value stays string.
        $stub = new TypedParamControllerStub();
        $this->container->set(TypedParamControllerStub::class, $stub);
        $this->rt->ws('/tickets/{id:slug}', TypedParamControllerStub::class);

        $this->open(1, '/tickets/abc-123');

        self::assertSame('abc-123', $stub->id);
        self::assertSame('string', $stub->idType);
    }

    // --- SSE ---

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
            static fn(): null => null,
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
            static fn(string $d): bool => true,
            static fn(): null => null,
        );

        self::assertFalse($handled);
    }

    #[Test]
    public function handleSseReturnsFalseWithoutAcceptHeader(): void
    {
        $this->rt->sse('/feed', FeedControllerStub::class);

        $handled = $this->rt->handleSse(
            $this->request('/feed'),
            static fn(string $d): bool => true,
            static fn(): null => null,
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
            static fn(string $d): bool => true,
            static fn(): null => null,
        );
    }

    // --- HTTP + RT coexistence ---

    #[Test]
    public function httpWsAndSseRoutesCompileAndList(): void
    {
        $this->rt->get('/users', ['UserController', 'index']);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);
        $this->rt->sse('/time', FeedControllerStub::class);
        $this->rt->compile();

        self::assertInstanceOf(RouteMatch::class, $this->rt->match('GET', ['users']));

        $methods = array_map(static fn(array $r): mixed => $r['methods'], $this->rt->list());
        self::assertContains(['GET'], $methods);
        self::assertContains(['WS'], $methods);
        self::assertContains(['SSE'], $methods);
    }

    #[Test]
    public function sseRouteSupportsWhereConstraint(): void
    {
        $route = $this->rt->sse('/events/{type}', FeedControllerStub::class);
        $route->where('type', '[a-z]+');

        self::assertSame(['type' => '[a-z]+'], $route->getWhere());
    }

    // --- helpers ---

    private function registerChat(): ChatControllerStub
    {
        $stub = new ChatControllerStub();
        $this->container->set(ChatControllerStub::class, $stub);
        $this->rt->ws('/chat/{room}', ChatControllerStub::class);

        return $stub;
    }

    /** Open a WS connection to a channel URL: create the socket, then route the URL (subscribe). */
    private function open(int $fd, string $path): bool
    {
        $request = $this->request($path);
        $this->hub->handleOpen($fd, $request);

        return $this->rt->dispatchWsOpen($fd, $request);
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
