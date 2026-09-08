<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * SSE through a REAL phpdot Server, the RouterRT serving as the handler — the
 * production shape end to end: the server's SSE branch (SseHandlerInterface +
 * Accept) fires, the route's PSR-15 middleware gates the stream, frames arrive
 * on the socket, and a refusing middleware stops the stream before it starts
 * and falls through to the HTTP pipeline.
 */
abstract class SseHarness extends TestCase
{
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
        $this->logFile = sys_get_temp_dir() . '/phpdot_rt_sse_' . getmypid() . '_' . $this->port . '.log';

        $cmd = [PHP_BINARY, __DIR__ . '/../Integration/Fixtures/sse_runner.php', (string) $this->port, $this->scenario()];

        $process = proc_open($cmd, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $this->logFile, 'w'],
            2 => ['file', $this->logFile, 'w'],
        ], $pipes);
        self::assertIsResource($process, 'failed to launch the SSE runner');
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
     * @param string $path
     *
     * @return string
     */
    protected function get(string $path): string
    {
        $fp = fsockopen('127.0.0.1', $this->port, $errno, $errstr, 5.0);
        self::assertIsResource($fp, "connect failed: {$errstr}");
        stream_set_timeout($fp, 5);

        fwrite($fp, 'GET ' . $path . " HTTP/1.1\r\nHost: 127.0.0.1\r\nAccept: text/event-stream\r\nConnection: close\r\n\r\n");

        $response = '';

        while (feof($fp) === false) {
            $chunk = fread($fp, 8192);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $response .= $chunk;

            if (stream_get_meta_data($fp)['timed_out'] === true) {
                break;
            }
        }

        fclose($fp);

        return $response;
    }

    /**
     * @param string $response
     *
     * @return string
     */
    protected function statusLine(string $response): string
    {
        $pos = strpos($response, "\r\n");

        return $pos === false ? $response : substr($response, 0, $pos);
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
                self::fail("the SSE runner exited before becoming ready:\n" . (string) @file_get_contents($this->logFile));
            }

            usleep(50_000);
        }

        self::fail("the SSE runner did not become ready in time:\n" . (string) @file_get_contents($this->logFile));
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
