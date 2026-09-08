<?php

declare(strict_types=1);

namespace PHPdot\Routing\RouterRT\Tests\Integration;

use PHPdot\Routing\RouterRT\Tests\Support\WebSocketClientTrait;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The multi-node path over REAL servers and REAL Redis: two nodes, each with
 * the RedisAdapter Hub, the subscriber relay, and the membership maintenance.
 * A room broadcast made on one node must reach the client connected to the
 * OTHER node — the publish/subscribe relay is the whole cluster — and the
 * surviving node must keep answering after its peer dies hard.
 */
final class TwoNodeClusterTest extends TestCase
{
    use WebSocketClientTrait;

    /** @var array<int, resource|null> node index => process */
    private array $nodes = [];

    /** @var array<int, int> node index => port */
    private array $ports = [];

    /** @var array<int, string> node index => log file */
    private array $logs = [];

    protected function setUp(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('ext-swoole is not loaded.');
        }

        $redisPort = (int) (getenv('REDIS_PORT') ?: 6380);
        $probe = @fsockopen('127.0.0.1', $redisPort, $errno, $errstr, 0.5);

        if ($probe === false) {
            self::markTestSkipped("compose redis not reachable on {$redisPort}; the cluster needs it.");
        }

        fclose($probe);

        foreach ([0, 1] as $i) {
            $this->ports[$i] = $this->findFreePort();
            $this->logs[$i] = sys_get_temp_dir() . '/phpdot_rt_node_' . getmypid() . '_' . $this->ports[$i] . '.log';

            $cmd = [
                PHP_BINARY,
                __DIR__ . '/Fixtures/cluster_node_runner.php',
                (string) $this->ports[$i],
                'node-' . $this->ports[$i] . '-' . uniqid(),
                (string) $redisPort,
            ];

            $process = proc_open($cmd, [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', $this->logs[$i], 'w'],
                2 => ['file', $this->logs[$i], 'w'],
            ], $pipes);
            self::assertIsResource($process, "failed to launch cluster node {$i}");
            $this->nodes[$i] = $process;

            $this->waitForNode($i);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->nodes as $process) {
            if (is_resource($process)) {
                proc_terminate($process, SIGKILL);
                proc_close($process);
            }
        }

        foreach ($this->logs as $log) {
            if (is_file($log)) {
                @unlink($log);
            }
        }
    }

    #[Test]
    public function aBroadcastCrossesNodesAndTheClusterSurvivesAPeersDeath(): void
    {
        /*
         * One client on EACH node, both in the lobby.
         */
        $onNodeOne = $this->openWebSocket($this->ports[0], '/room/lobby');
        $onNodeTwo = $this->openWebSocket($this->ports[1], '/room/lobby');

        usleep(300_000);

        /*
         * A message sent THROUGH node one broadcasts to the room — which the
         * relay must carry to node two's connection too.
         */
        fwrite($onNodeOne, $this->encodeMaskedTextFrame('{"event":"message","data":{"text":"cross"},"ack":1}'));

        $heardOne = $this->nextEvent($onNodeOne);
        $heardTwo = $this->nextEvent($onNodeTwo);

        self::assertSame('said', $heardOne['event'], 'the sender node did not hear its own room broadcast');
        self::assertSame('said', $heardTwo['event'], 'the OTHER node never received the relayed broadcast');
        self::assertSame('cross', $heardTwo['data']['text']);

        /*
         * The peer dies HARD — no drain, no farewell, the membership that
         * maintenance must eventually reap. The survivor keeps answering.
         */
        posix_kill(proc_get_status($this->nodes[0])['pid'], SIGKILL);
        usleep(500_000);

        fwrite($onNodeTwo, $this->encodeMaskedTextFrame('{"event":"message","data":{"text":"after"}}'));

        $heardAfter = $this->nextEvent($onNodeTwo);

        self::assertSame('said', $heardAfter['event'], 'the surviving node stopped answering after its peer died');
        self::assertSame('after', $heardAfter['data']['text']);

        fclose($onNodeOne);
        fclose($onNodeTwo);
    }

    /**
     * @param resource $socket
     *
     * @return array<string, mixed>
     */
    private function nextEvent($socket): array
    {
        $frame = $this->readTextFrame($socket);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($frame, true, 16);

        self::assertIsArray($decoded, "a non-JSON frame arrived: {$frame}");

        /*
         * Skip the bookkeeping frames that race the broadcast: ack replies
         * and the presence roster the join sends to the arriving socket.
         */
        $event = (string) ($decoded['event'] ?? '');

        if ($event === 'ack' || str_starts_with($event, 'presence')) {
            return $this->nextEvent($socket);
        }

        return $decoded;
    }

    /**
     * @param int $i
     *
     * @return void
     */
    private function waitForNode(int $i): void
    {
        $deadline = microtime(true) + 8.0;

        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $this->ports[$i], $errno, $errstr, 0.2);

            if (is_resource($fp)) {
                fclose($fp);

                return;
            }

            if (is_resource($this->nodes[$i]) && proc_get_status($this->nodes[$i])['running'] === false) {
                self::fail("cluster node {$i} exited before becoming ready:\n" . (string) @file_get_contents($this->logs[$i]));
            }

            usleep(50_000);
        }

        self::fail("cluster node {$i} did not become ready in time:\n" . (string) @file_get_contents($this->logs[$i]));
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
}
