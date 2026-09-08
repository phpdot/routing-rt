<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use PHPdot\Http\Factory\ResponseFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * A PSR-15 middleware that refuses: answers a 401 and never proceeds. Proves
 * an SSE route's chain stops the stream before it starts.
 */
final class RejectingMiddleware implements MiddlewareInterface
{
    public static bool $refuses = true;

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (self::$refuses) {
            return (new ResponseFactory())->createResponse(401);
        }

        return $handler->handle($request);
    }
}
