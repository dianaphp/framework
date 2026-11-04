<?php

namespace Diana\Tests\Cache;

use DateInterval;
use DateTime;
use Diana\Cache\FileCache;
use Diana\Cache\JsonFileCache;
use Diana\Cache\PhpFileCache;
use Diana\Config\Config;
use Diana\Framework\Core\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FileCacheTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/diana-cache-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public static function drivers(): iterable
    {
        yield 'file' => [FileCache::class];
        yield 'json' => [JsonFileCache::class];
        yield 'php' => [PhpFileCache::class];
    }

    private function cache(string $driver): FileCache
    {
        $config = new Config();
        $config->set('cachePath', 'tmp');
        $app = $this->createMock(Application::class);
        $app->method('path')->with('tmp')->willReturn($this->directory);
        return new $driver($config, $app);
    }

    #[DataProvider('drivers')]
    public function testValuesRoundtripWithTheirOriginalTypes(string $driver): void
    {
        $cache = $this->cache($driver);
        $object = new \stdClass();
        $object->value = ['a' => 1];
        $values = [null, '', false, true, 0, 42, -7, 1.0, 1.25, [], ['a' => 1],
            ['nested' => [null, '', false, ['x' => 1]]], "O'Reilly\\", "\x00\xff",
            "DIANA-CACHE-SERIALIZED\nb:0;", $object, ['object' => $object]];

        foreach ($values as $index => $value) {
            $key = 'value.' . $index;
            self::assertTrue($cache->set($key, $value));
            self::assertTrue($cache->has($key));
            // Reopen the driver to verify persisted data, including object types.
            self::assertSame(serialize($value), serialize($this->cache($driver)->get($key, 'missing')));
        }
        self::assertSame('missing', $cache->get('absent', 'missing'));
    }

    #[DataProvider('drivers')]
    public function testTtlAndExpiration(string $driver): void
    {
        $cache = $this->cache($driver);
        foreach ([60, new DateInterval('PT60S'), null] as $ttl) {
            self::assertTrue($cache->set('entry', 'value', $ttl));
            self::assertSame('value', $cache->get('entry'));
        }

        foreach ([0, -1, new DateInterval('PT0S'), DateInterval::createFromDateString('-1 second')] as $ttl) {
            self::assertTrue($cache->set('entry', 'old'));
            self::assertTrue($cache->set('entry', 'new', $ttl));
            self::assertFalse($cache->has('entry'));
            self::assertSame('missing', $cache->get('entry', 'missing'));
        }

        foreach ([time() - 1, time()] as $expiration) {
            self::assertTrue($cache->set('entry', 'expired'));
            file_put_contents($this->directory . '/entry' . $cache->getMetaExtension(), (string) $expiration);
            // get() itself must check expiry, especially for the PHP driver.
            self::assertSame('missing', $cache->get('entry', 'missing'));
            self::assertFalse($cache->has('entry'));
            self::assertFileDoesNotExist($this->directory . '/entry' . $cache->getCacheExtension());
        }
    }

    #[DataProvider('drivers')]
    public function testExpirationCalculation(string $driver): void
    {
        $cache = $this->cache($driver);
        $date = new DateTime('2026-01-01 00:00:00 UTC');
        $timestamp = $date->getTimestamp();
        self::assertSame(0, $cache->calculateExpiration($date, null));
        self::assertSame($timestamp, $cache->calculateExpiration($date, 0));
        self::assertSame($timestamp + 60, $cache->calculateExpiration($date, 60));
        self::assertSame($timestamp - 60, $cache->calculateExpiration($date, -60));
        self::assertSame($timestamp + 60, $cache->calculateExpiration($date, new DateInterval('PT60S')));
        self::assertSame($timestamp, $date->getTimestamp());
    }

    #[DataProvider('drivers')]
    public function testMultipleOperationsAndClear(string $driver): void
    {
        $cache = $this->cache($driver);
        $values = ['array' => ['a' => 1], 'empty' => '', 'null' => null];
        self::assertTrue($cache->setMultiple((static function () use ($values) { yield from $values; })(), 60));
        self::assertSame($values + ['absent' => 'missing'], $cache->getMultiple(array_keys($values + ['absent' => null]), 'missing'));
        self::assertTrue($cache->deleteMultiple(new \ArrayIterator(['array', 'empty', 'absent'])));
        self::assertFalse($cache->has('array'));
        self::assertFalse($cache->has('empty'));
        self::assertTrue($cache->has('null'));
        self::assertTrue($cache->setMultiple($values, new DateInterval('PT60S')));
        self::assertTrue($cache->setMultiple($values, 0));
        self::assertSame(array_fill_keys(array_keys($values), 'missing'), $cache->getMultiple(array_keys($values), 'missing'));
        self::assertTrue($cache->setMultiple($values));
        self::assertTrue($cache->clear());
        self::assertSame([], glob($this->directory . '/*'));
        self::assertTrue($cache->clear());
    }

    public function testJsonCacheContainsJsonAndDiscardsLegacyEntries(): void
    {
        $cache = $this->cache(JsonFileCache::class);
        self::assertTrue($cache->set('entry', ['a' => 1]));
        $file = $this->directory . '/entry' . $cache->getCacheExtension();
        $data = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['a' => 1], $data['value']);

        foreach (['1', 'old string', '', '{"a":1}'] as $legacy) {
            self::assertTrue($cache->set('entry', 'value'));
            file_put_contents($file, $legacy);
            self::assertSame('missing', $cache->get('entry', 'missing'));
            self::assertFalse($cache->has('entry'));
        }
    }

    public function testMultipleOperationsContinueAfterAnIndividualFailure(): void
    {
        $cache = $this->getMockBuilder(FileCache::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['setByTimestamp', 'delete'])
            ->getMock();
        $written = [];
        $deleted = [];
        $cache->method('setByTimestamp')->willReturnCallback(static function ($key) use (&$written) {
            $written[] = $key;
            return $key !== 'bad';
        });
        $cache->method('delete')->willReturnCallback(static function ($key) use (&$deleted) {
            $deleted[] = $key;
            return $key !== 'bad';
        });
        self::assertFalse($cache->setMultiple(['bad' => 1, 'good' => 2]));
        self::assertSame(['bad', 'good'], $written);
        self::assertFalse($cache->deleteMultiple(['bad', 'good']));
        self::assertSame(['bad', 'good'], $deleted);
    }

    public function testRawPhpStringsRemainUsableAsIdeAliasFiles(): void
    {
        $cache = $this->cache(FileCache::class);
        $cache->setCacheExtension('.cache.php');
        $source = '<?php class ExampleAlias {}';
        self::assertTrue($cache->set('aliases', $source));
        self::assertSame($source, file_get_contents($this->directory . '/aliases.cache.php'));
    }
}
