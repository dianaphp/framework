<?php

namespace Diana\Tests\Core;

use PHPUnit\Framework\TestCase;

class HttpResponseTest extends TestCase
{
    public function testStatusHeadersCookiesJsonAndRedirectsReachTheHttpClient(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertNotFalse($socket, $error);
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $log = tmpfile();
        $process = proc_open([
            PHP_BINARY, '-S', $address, __DIR__ . '/../Fixtures/http-response.php',
        ], [0 => ['pipe', 'r'], 1 => $log, 2 => $log], $pipes, null, array_merge(getenv(), [
            'DIANA_TEST_AUTOLOAD' => dirname((new \ReflectionClass(\Composer\Autoload\ClassLoader::class))->getFileName(), 2) . '/autoload.php',
        ]));
        self::assertIsResource($process);

        try {
            $ready = false;
            for ($attempt = 0; $attempt < 100; $attempt++) {
                $connection = @stream_socket_client('tcp://' . $address, $errno, $error, 0.05);
                if ($connection) {
                    fclose($connection);
                    $ready = true;
                    break;
                }
                usleep(20000);
            }
            self::assertTrue($ready, 'PHP HTTP test server did not start.');
            $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true, 'timeout' => 5]]);

            [$body, $headers] = $this->fetch('http://' . $address . '/created', $context);
            self::assertStringContainsString('201 Created', $headers[0]);
            self::assertSame('{"created":true}', $body);
            self::assertContains('Content-Type: application/json', $headers);
            self::assertContains('Location: /resource/1', $headers);
            self::assertContains('X-Custom: preserved', $headers);
            self::assertContains('Set-Cookie: first=1; Path=/', $headers);
            self::assertContains('Set-Cookie: second=2; Path=/', $headers);

            [$body, $headers] = $this->fetch('http://' . $address . '/data', $context);
            self::assertSame('{"ok":true}', $body);
            self::assertStringContainsString('200 OK', $headers[0]);
            self::assertContains('Content-Type: application/json', $headers);

            [$body, $headers] = $this->fetch('http://' . $address . '/redirect', $context);
            self::assertSame('', $body);
            self::assertStringContainsString('307 Temporary Redirect', $headers[0]);
            self::assertContains('Location: /data', $headers);
        } finally {
            proc_terminate($process);
            fclose($pipes[0]);
            proc_close($process);
            fclose($log);
        }
    }

    private function fetch(string $url, mixed $context): array
    {
        $stream = fopen($url, 'r', false, $context);
        self::assertIsResource($stream);
        try {
            return [stream_get_contents($stream), stream_get_meta_data($stream)['wrapper_data']];
        } finally {
            fclose($stream);
        }
    }
}
