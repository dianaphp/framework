<?php

namespace Diana\Tests\IO;

use Diana\IO\HttpRequest;
use PHPUnit\Framework\TestCase;

class HttpRequestTest extends TestCase
{
    private array $server;
    private array $get;

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        $this->get = $_GET;
        $_SERVER['HTTP_HOST'] = 'example.test';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REQUEST_URI'] = '/captured?name=Max+Mustermann&flag';
        $_GET = ['original' => ['preserved']];
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        $_GET = $this->get;
    }

    public function testQueryParametersAreDecodedWithoutChangingGlobalInput(): void
    {
        $query = 'name=Max+Mustermann&tag[]=a&tag[]=b&flag&empty=&encoded=a%26b%3Dc&nested[key]=value';
        $request = new HttpRequest('https://example.test/search?' . $query);

        self::assertSame('/search', $request->getRoute());
        self::assertSame($query, $request->getQuery());
        self::assertSame([
            'name' => 'Max Mustermann', 'tag' => ['a', 'b'], 'flag' => '',
            'empty' => '', 'encoded' => 'a&b=c', 'nested' => ['key' => 'value'],
        ], $request->getQueryParameters());
        self::assertSame(['original' => ['preserved']], $_GET);

        $other = new HttpRequest('/other?name=Other');
        self::assertSame(['name' => 'Other'], $other->getQueryParameters());
        self::assertSame('Max Mustermann', $request->getQueryParameters()['name']);
        self::assertSame([], (new HttpRequest('/plain'))->getQueryParameters());
        self::assertSame(['original' => ['preserved']], $_GET);
    }

    public function testDefaultUrlSeparatesTheCapturedRouteAndQuery(): void
    {
        $request = new HttpRequest();
        self::assertSame('/captured', $request->getRoute());
        self::assertSame('name=Max+Mustermann&flag', $request->getQuery());
        self::assertSame(['name' => 'Max Mustermann', 'flag' => ''], $request->getQueryParameters());
        self::assertSame(['original' => ['preserved']], $_GET);
    }

    public function testEmptyAndZeroQueriesAndMissingServerUri(): void
    {
        foreach (['/search', '/search?', '/search?0'] as $url) {
            $request = new HttpRequest($url);
            self::assertSame('/search', $request->getRoute());
            self::assertSame($url === '/search?0' ? ['0' => ''] : [], $request->getQueryParameters());
        }
        unset($_SERVER['REQUEST_URI']);
        self::assertSame('/', (new HttpRequest())->getRoute());
    }
    public function testUrlComponentsAndRelativeTargets(): void
    {
        foreach ([
            ['https://other.test', 'https', 'other.test', '/', ''],
            ['https://other.test?x=1', 'https', 'other.test', '/', 'x=1'],
            ['//other.test:8443/a', 'http', 'other.test:8443', '/a', ''],
            ['?x=1', 'http', 'example.test', '/', 'x=1'],
            ['relative/path?0', 'http', 'example.test', '/relative/path', '0'],
            ['/a#fragment', 'http', 'example.test', '/a', ''],
        ] as [$url, $protocol, $host, $route, $query]) {
            $request = new HttpRequest($url);
            self::assertSame($protocol, $request->getProtocol());
            self::assertSame($host, $request->getHost());
            self::assertSame($route, $request->getRoute());
            self::assertSame($query, $request->getQuery());
        }
    }

    public function testMissingServerDataHasUsableDefaults(): void
    {
        $_SERVER = [];
        foreach ([new HttpRequest(), HttpRequest::fromServer([])] as $request) {
            self::assertSame('http', $request->getProtocol());
            self::assertSame('localhost', $request->getHost());
            self::assertSame('/', $request->getRoute());
            self::assertSame('GET', $request->getMethod());
        }
    }

    public function testHttpsDetectionAndContentHeaders(): void
    {
        foreach ([['HTTPS' => 'on'], ['HTTPS' => '1'], ['SERVER_PORT' => '443']] as $connection) {
            $request = HttpRequest::fromServer($connection + [
                'SERVER_PROTOCOL' => 'HTTP/1.1', 'HTTP_HOST' => 'secure.test',
                'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '0',
                'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '?x=1',
            ]);
            self::assertSame('https', $request->getProtocol());
            self::assertSame('secure.test', $request->getHost());
            self::assertSame('/', $request->getRoute());
            self::assertSame('x=1', $request->getQuery());
            self::assertSame('POST', $request->getMethod());
            self::assertSame('application/json', $request->getHeader('Content-Type'));
            self::assertSame('0', $request->getHeader('Content-Length'));
        }
        $_SERVER['HTTPS'] = 'on';
        self::assertSame('https', (new HttpRequest('/'))->getProtocol());
        self::assertSame('http', HttpRequest::fromServer(['HTTPS' => 'off'])->getProtocol());
    }

    public function testProxyHeadersRequireAnExplicitlyTrustedPeer(): void
    {
        $server = [
            'REMOTE_ADDR' => '192.0.2.10', 'HTTP_HOST' => 'internal.test',
            'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'public.test:8443',
            'REQUEST_URI' => '/path?q=1',
        ];
        foreach ([[], ['192.0.2.11']] as $trusted) {
            $request = HttpRequest::fromServer($server, $trusted);
            self::assertSame('http', $request->getProtocol());
            self::assertSame('internal.test', $request->getHost());
        }
        $request = HttpRequest::fromServer($server, ['192.0.2.10']);
        self::assertSame('https', $request->getProtocol());
        self::assertSame('public.test:8443', $request->getHost());
        self::assertSame('/path', $request->getRoute());
        self::assertSame('q=1', $request->getQuery());
        $server['HTTP_X_FORWARDED_PROTO'] = 'javascript';
        $server['HTTP_X_FORWARDED_HOST'] = 'evil.test/path';
        $request = HttpRequest::fromServer($server, ['192.0.2.10']);
        self::assertSame('http', $request->getProtocol());
        self::assertSame('internal.test', $request->getHost());
    }

    public function testMalformedUrlsAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HttpRequest('https://');
    }

}
