# phpdot/routing-rt

Real-time routing for WebSocket and SSE. Extends [phpdot/routing](https://github.com/phpdot/routing) with `ws()` and `sse()` methods while reusing the same trie-based matcher, route features, groups, middleware, and URL patterns.

## Requirements

- PHP 8.3+
- phpdot/routing ^1.1

## Installation

```bash
composer require phpdot/routing-rt
```

## Quick Start

```php
use PHPdot\Routing\RouterRT\RouterRT;

$app = new RouterRT($container, $responseFactory);

// HTTP routes — inherited from Router
$app->get('/chat/{room}', [ChatPageController::class, 'show']);

// WebSocket route — same path, no collision
$app->ws('/chat/{room}', [ChatController::class, 'index']);

// SSE route
$app->sse('/dashboard/{id:int}', [DashboardController::class, 'stream']);
```

## How It Works

HTTP routes compile into Router's trie. WS/SSE routes compile into a separate trie built from the same engine. `matchRt()` reads request headers to determine the protocol:

- `Upgrade: websocket` — matches against WS routes
- `Accept: text/event-stream` — matches against SSE routes
- Neither — returns `null`, fall back to HTTP

```php
$match = $app->matchRt($psrRequest);

if ($match !== null) {
    // Real-time route matched
    $handler = $match->getRoute()->getHandler();
    $params  = $match->getParameters();
} else {
    // Normal HTTP
    $response = $app->handle($psrRequest);
}
```

## Contracts

### WebSocketController

```php
use PHPdot\Routing\RouterRT\Contracts\WebSocketController;
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

Server-agnostic WebSocket connection wrapper. No Swoole dependency — uses closures for send/close, making it fully testable.

```php
$conn->id();                          // File descriptor
$conn->send('text');                  // Send text frame
$conn->send(['key' => 'value']);      // Auto JSON-encode
$conn->sendBinary($bytes);           // Send binary frame
$conn->close(1000, 'bye');           // Close connection
$conn->param('room');                // Route parameter
$conn->attribute('user_id');         // Request attribute (from middleware)
$conn->request();                    // Original upgrade request
```

## SSEWriter

SSE protocol formatting with automatic closed-state tracking.

```php
$writer->event('update', ['id' => 1]);           // Named event
$writer->event('update', 'payload', '42');        // With ID
$writer->data('unnamed payload');                  // Unnamed data
$writer->comment('keep-alive');                    // Comment (keep-alive)
$writer->retry(5000);                              // Reconnection interval (ms)
$writer->close();                                  // Close stream
$writer->isClosed();                               // Check state
```

## Frame

Readonly value object for WebSocket message data.

```php
use PHPdot\Routing\RouterRT\Frame;
use PHPdot\Routing\RouterRT\Opcode;

$frame = new Frame($data, Opcode::Text);
$frame->data;    // string
$frame->opcode;  // Opcode::Text | Opcode::Binary
```

## Route Features

WS and SSE routes return the same `Route` object as HTTP routes. All chaining works:

```php
$app->ws('/chat/{room}', [ChatController::class, 'index'])
    ->name('ws.chat')
    ->middleware(AuthMiddleware::class)
    ->where('room', '[a-z]+');

$app->sse('/events/{type}', [EventController::class, 'stream'])
    ->name('sse.events');
```

Groups apply prefixes to WS/SSE routes:

```php
$app->group('/api/v1', function ($group) use ($app) {
    $app->ws('/chat/{room}', [ChatController::class, 'index']);
    $app->sse('/feed', [FeedController::class, 'stream']);
});
// Matches: /api/v1/chat/{room}, /api/v1/feed
```

## What Is NOT In This Package

- Rooms, broadcasting, presence — see `phpdot/channel`
- Connection management — framework-level wiring
- Swoole event registration — framework-level wiring
- Cross-worker pub/sub — see `phpdot/channel`

## Testing

```bash
composer install
vendor/bin/phpunit
```

## License

MIT
