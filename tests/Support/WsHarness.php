<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * Boots the WebSocket runner (a real phpdot Server with a WebSocket master,
 * RouterRT composed through the adapter every consumer must write) in a
 * separate process and drives it with the raw RFC 6455 client.
 *
 * The client half is lifted from the server package's WebSocketClientTrait —
 * its tests namespace is not autoloadable cross-package, and the retry-loop
 * handshake in it is a lesson already paid for.
 *
 * @author Omar Hamdan <omar@phpdot.com>
 * @license MIT
 */
abstract class WsHarness extends TestCase
{
    use WebSocketClientTrait;

    /** @var resource|null */
    private $process = null;

    private int $port = 0;

    private string $logFile = '';

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole is not loaded.');
        }

        $this->port = $this->findFreePort();
        $this->logFile = sys_get_temp_dir() . '/phpdot_rt_ws_' . getmypid() . '_' . $this->port . '.log';

        $cmd = [PHP_BINARY, __DIR__ . '/../Integration/Fixtures/ws_runner.php', (string) $this->port, $this->scenario()];

        $process = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->logFile, 'w'],
            2 => ['file', $this->logFile, 'w'],
        ], $pipes);
        self::assertIsResource($process, 'failed to launch the WS runner');
        $this->process = $process;

        $this->waitForServer();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, SIGTERM);

            $deadline = microtime(true) + 2.0;
            while (microtime(true) < $deadline) {
                if (proc_get_status($this->process)['running'] === false) {
                    break;
                }
                usleep(50_000);
            }

            if (proc_get_status($this->process)['running'] === true) {
                foreach ($this->processTree((int) proc_get_status($this->process)['pid']) as $pid) {
                    @posix_kill($pid, SIGKILL);
                }
            }

            proc_close($this->process);
            $this->process = null;
        }

        if (is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    /**
     * Which scenario the runner serves.
     *
     * @return string
     */
    abstract protected function scenario(): string;

    /**
     * The port the runner listens on.
     *
     * @return int
     */
    protected function portNumber(): int
    {
        return $this->port;
    }

    /**
     * @return int
     */
    private function findFreePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock, "could not allocate a free port: {$errstr}");
        $name = stream_socket_get_name($sock, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);
        fclose($sock);

        return $port;
    }

    /**
     * @return void
     */
    private function waitForServer(): void
    {
        $deadline = microtime(true) + 5.0;

        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2);

            if (is_resource($fp)) {
                fclose($fp);

                return;
            }

            if (is_resource($this->process) && proc_get_status($this->process)['running'] === false) {
                self::fail("the WS runner exited before becoming ready:\n" . (string) @file_get_contents($this->logFile));
            }

            usleep(50_000);
        }

        self::fail("the WS runner did not become ready in time:\n" . (string) @file_get_contents($this->logFile));
    }

    /**
     * @param int $pid
     *
     * @return list<int>
     */
    private function processTree(int $pid): array
    {
        $pids = [$pid];
        $children = (string) shell_exec('pgrep -P ' . $pid . ' 2>/dev/null');

        foreach (array_filter(array_map('intval', explode("\n", trim($children)))) as $child) {
            foreach ($this->processTree($child) as $descendant) {
                $pids[] = $descendant;
            }
        }

        return $pids;
    }
}
