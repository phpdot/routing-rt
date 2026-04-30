# phpdot/routing-rt

Real-time routing for WebSocket and SSE. Extends [phpdot/routing](https://github.com/phpdot/routing) — same API, same features, two extra methods.

## Requirements

- PHP 8.3+
- phpdot/routing ^1.1

## Installation

```bash
composer require phpdot/routing-rt
```

## Usage

```php
use PHPdot\Routing\RouterRT\RouterRT;

$app = new RouterRT($container, $responseFactory);

// HTTP — same as Router
$app->get('/chat/{room}', [ChatPageController::class, 'show']);
$app->post('/users', [UserController::class, 'store']);

// WebSocket
$app->ws('/chat/{room}', [ChatController::class, 'index']);

// SSE
$app->sse('/dashboard/{id:int}', [DashboardController::class, 'stream']);

// Everything works: groups, middleware, names, where, expose
$app->group('/api', function ($group) use ($app) {
    $app->ws('/chat/{room}', [ChatController::class, 'index'])
        ->name('ws.chat')
        ->middleware(AuthMiddleware::class);

    $app->sse('/feed', [FeedController::class, 'stream'])
        ->name('sse.feed');
});

// list() returns all routes — HTTP, WS, SSE
$app->list();

// compile() compiles everything
$app->compile();
```

Same path can serve both HTTP and WebSocket without collision.

### Inside the phpdot framework

`RouterRT` carries `#[Singleton]`, so when used with `phpdot/package` it's auto-wired by the container — no manual `new RouterRT(...)` needed. To make `RouterRT` the default for everywhere your app asks for a `Router`, override the binding in your application boot:

```php
use PHPdot\Routing\Router;
use PHPdot\Routing\RouterRT\RouterRT;

$builder->register(
    Router::class,
    new ScopedDefinition(scope: Scope::SINGLETON, implementation: RouterRT::class),
);
```

Anywhere your code asks for `Router::class`, the container hands back a `RouterRT` (which extends `Router`). All HTTP route registrations work unchanged; `->ws()` and `->sse()` become available.

When served through `phpdot/server-swoole`, the server auto-detects `RouterRT` (via its `WebSocketHandlerInterface` and `SseHandlerInterface` markers) and wires Swoole's `onOpen` / `onMessage` / `onClose` event hooks to the router automatically. No additional configuration needed.

## Contracts

### WebSocketController

```php
use PHPdot\Routing\RouterRT\Contracts\WebSocketController;
use PHPdot\Routing\RouterRT\Connection;
use PHPdot\Routing\RouterRT\Frame;

final class ChatController implements WebSocketController
{
    public function __construct(
        private Connection $conn,
    ) {}

    public function onOpen(): void
    {
        $room = $this->conn->param('room');
        $this->conn->send(['event' => 'joined', 'room' => $room]);
    }

    public function onMessage(Frame $frame): void
    {
        $this->conn->send(['echo' => $frame->data]);
    }

    public function onClose(int $code, string $reason): void {}
}
```

### SSEController

```php
use PHPdot\Routing\RouterRT\Contracts\SSEController;
use PHPdot\Routing\RouterRT\SSEWriter;

final class DashboardController implements SSEController
{
    public function stream(SSEWriter $writer): void
    {
        $writer->retry(3000);

        while (!$writer->isClosed()) {
            $writer->event('metrics', ['cpu' => 42, 'mem' => 78]);
            sleep(1);
        }
    }
}
```

## Connection

Server-agnostic WebSocket connection. No Swoole dependency — fully testable.

```php
$conn->id();                          // File descriptor
$conn->send('text');                  // Send text frame
$conn->send(['key' => 'value']);      // Auto JSON-encode
$conn->sendBinary($bytes);           // Send binary frame
$conn->close(1000, 'bye');           // Close connection
$conn->param('room');                // Route parameter
$conn->params();                     // All route parameters
$conn->attribute('user_id');         // Request attribute (from middleware)
$conn->request();                    // Original upgrade request
```

## SSEWriter

SSE protocol formatting with closed-state tracking.

```php
$writer->event('update', ['id' => 1]);           // Named event
$writer->event('update', 'payload', '42');        // With ID
$writer->data('unnamed payload');                  // Unnamed data
$writer->comment('keep-alive');                    // Comment
$writer->retry(5000);                              // Reconnection interval (ms)
$writer->close();                                  // Close stream
$writer->isClosed();                               // Check state
```

## Frame

```php
use PHPdot\Routing\RouterRT\Frame;
use PHPdot\Routing\RouterRT\Opcode;

$frame = new Frame($data, Opcode::Text);
$frame->data;    // string
$frame->opcode;  // Opcode::Text | Opcode::Binary
```

## What Is NOT In This Package

- Rooms, broadcasting, presence — `phpdot/channel`
- Connection management — framework wiring
- Swoole event registration — framework wiring

## Testing

```bash
composer check
```

## License

MIT
