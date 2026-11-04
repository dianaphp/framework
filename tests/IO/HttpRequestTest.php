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
}
