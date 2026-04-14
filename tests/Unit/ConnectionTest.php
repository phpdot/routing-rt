<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Unit;

use Nyholm\Psr7\ServerRequest;
use PHPdot\Routing\RouterRT\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ConnectionTest extends TestCase
{
    /** @var list<string> */
    private array $sent;

    /** @var list<string> */
    private array $sentBinary;

    /** @var array{code: int, reason: string}|null */
    private array|null $closedWith;

    private Connection $conn;

    protected function setUp(): void
    {
        $this->sent = [];
        $this->sentBinary = [];
        $this->closedWith = null;

        $this->conn = new Connection(
            fd: 42,
            sendFn: function (string $data): bool {
                $this->sent[] = $data;
                return true;
            },
            sendBinaryFn: function (string $data): bool {
                $this->sentBinary[] = $data;
                return true;
            },
            closeFn: function (int $code, string $reason): bool {
                $this->closedWith = ['code' => $code, 'reason' => $reason];
                return true;
            },
            upgradeRequest: new ServerRequest('GET', '/chat/general'),
        );
    }

    #[Test]
    public function returnsFileDescriptor(): void
    {
        self::assertSame(42, $this->conn->id());
    }

    #[Test]
    public function sendsTextString(): void
    {
        $this->conn->send('hello');

        self::assertSame(['hello'], $this->sent);
    }

    #[Test]
    public function sendsArrayAsJson(): void
    {
        $this->conn->send(['type' => 'ping']);

        self::assertSame(['{"type":"ping"}'], $this->sent);
    }

    #[Test]
    public function sendsBinaryData(): void
    {
        $this->conn->sendBinary("\x00\x01");

        self::assertSame(["\x00\x01"], $this->sentBinary);
    }

    #[Test]
    public function closesWithCodeAndReason(): void
    {
        $this->conn->close(1001, 'going away');

        self::assertSame(['code' => 1001, 'reason' => 'going away'], $this->closedWith);
    }

    #[Test]
    public function closesWithDefaults(): void
    {
        $this->conn->close();

        self::assertSame(['code' => 1000, 'reason' => ''], $this->closedWith);
    }

    #[Test]
    public function routeParams(): void
    {
        $this->conn->setParams(['room' => 'general', 'id' => 5]);

        self::assertSame('general', $this->conn->param('room'));
        self::assertSame(5, $this->conn->param('id'));
        self::assertNull($this->conn->param('missing'));
        self::assertSame('fallback', $this->conn->param('missing', 'fallback'));
        self::assertSame(['room' => 'general', 'id' => 5], $this->conn->params());
    }

    #[Test]
    public function attributesFallBackToUpgradeRequest(): void
    {
        $request = (new ServerRequest('GET', '/chat'))
            ->withAttribute('user_id', 99);

        $conn = $this->makeConnection($request);

        self::assertSame(99, $conn->attribute('user_id'));
        self::assertNull($conn->attribute('missing'));
    }

    #[Test]
    public function setAttributesOverrideRequestAttributes(): void
    {
        $request = (new ServerRequest('GET', '/chat'))
            ->withAttribute('user_id', 99);

        $conn = $this->makeConnection($request);
        $conn->setAttributes(['user_id' => 200]);

        self::assertSame(200, $conn->attribute('user_id'));
    }

    #[Test]
    public function exposesUpgradeRequest(): void
    {
        self::assertSame('/chat/general', $this->conn->request()->getUri()->getPath());
    }

    private function makeConnection(\Psr\Http\Message\ServerRequestInterface $request): Connection
    {
        return new Connection(
            fd: 1,
            sendFn: fn(string $d): bool => true,
            sendBinaryFn: fn(string $d): bool => true,
            closeFn: fn(int $c, string $r): bool => true,
            upgradeRequest: $request,
        );
    }
}
