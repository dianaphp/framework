<?php

namespace Diana\Tests\IO;

use Diana\IO\HttpRequest;
use Diana\IO\Response;
use JsonSerializable;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testHeadersCanBeReadSetAndReplacedAsAFlatMap(): void
    {
        $response = new Response('created', 201, ['X-Initial' => 'yes']);
        self::assertSame('yes', $response->getHeader('x-initial'));
        self::assertNull($response->getHeader('missing'));
        $response->setHeader('x-initial', 'updated');
        self::assertSame(['X-Initial' => 'updated'], $response->getHeader());

        $headers = ['Location' => '/created', 'Set-Cookie' => ['a=1', 'b=2']];
        $response->setHeader($headers);
        self::assertSame($headers, $response->getHeader());
        self::assertSame(201, $response->getStatusCode());
    }

    public function testSharedRequestHeadersRemainReadable(): void
    {
        $request = new HttpRequest('/example', headers: ['Accept' => 'application/json']);
        self::assertSame('application/json', $request->getHeader('accept'));
        $request->setHeader(['X-Test' => 'value']);
        self::assertSame(['X-Test' => 'value'], $request->getHeader());
    }

    public function testStructuredBodiesReceiveJsonContentType(): void
    {
        foreach ([['ok' => true], new class implements JsonSerializable {
            public function jsonSerialize(): mixed { return ['ok' => true]; }
        }] as $body) {
            $response = new Response($body);
            self::assertSame('{"ok":true}', (string) $response);
            self::assertSame('application/json', $response->getHeader('content-type'));
        }
    }

    public function testExplicitContentTypeIsPreserved(): void
    {
        $response = new Response(['ok' => true], 201, ['content-type' => 'application/problem+json']);
        self::assertSame(['content-type' => 'application/problem+json'], $response->getHeader());
        self::assertSame('plain', (string) new Response('plain'));
        self::assertNull((new Response('plain'))->getHeader('Content-Type'));
        self::assertSame('0', (string) new Response(0));
        self::assertSame('', (string) new Response(null));
    }

    public function testInvalidJsonBodyRaisesAnExplicitError(): void
    {
        $this->expectException(\JsonException::class);
        (string) new Response(["\xB1"]);
    }
}
