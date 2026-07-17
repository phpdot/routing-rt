<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Stubs;

use Psr\Container\ContainerInterface;
use RuntimeException;

final class StubContainer implements ContainerInterface
{
    /** @var array<string, object> */
    private array $services = [];

    public function set(string $id, object $service): void
    {
        $this->services[$id] = $service;
    }

    public function get(string $id): object
    {
        if (!isset($this->services[$id])) {
            if (class_exists($id)) {
                return new $id();
            }
            throw new RuntimeException("Service '{$id}' not found.");
        }

        return $this->services[$id];
    }

    public function has(string $id): bool
    {
        return isset($this->services[$id]) || class_exists($id);
    }
}
