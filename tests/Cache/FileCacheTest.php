<?php

namespace Diana\Tests\Cache;

use DateInterval;
use DateTime;
use Diana\Cache\FileCache;
use Diana\Cache\Exceptions\InvalidCacheKeyException;
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
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        ) as $file) {
            $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
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

    private function entryFile(FileCache $cache, string $key, bool $meta = false): string
    {
        return (new \ReflectionMethod(FileCache::class, $meta ? 'getMetaFileName' : 'getCacheFileName'))->invoke($cache, $key);
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
            file_put_contents($this->entryFile($cache, 'entry', true), (string) $expiration);
            // get() itself must check expiry, especially for the PHP driver.
            self::assertSame('missing', $cache->get('entry', 'missing'));
            self::assertFalse($cache->has('entry'));
            self::assertFileDoesNotExist($this->entryFile($cache, 'entry'));
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
        $file = $this->entryFile($cache, 'entry');
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

    #[DataProvider('drivers')]
    public function testInvalidKeysCannotAccessFiles(string $driver): void
    {
        $cache = $this->cache($driver);
        $invalid = ['', '../escape', '..\\escape', '/absolute', 'C:\\absolute',
            'a/b', 'a\\b', 'a{b', 'a}b', 'a(b', 'a)b', 'a@b', 'a:b', "a\0b", "a\nb", "a\x7fb"];
        foreach ($invalid as $key) {
            foreach (['get', 'has', 'delete', 'set', 'setExpired', 'timestamp'] as $method) {
                try {
                    match ($method) {
                        'set' => $cache->set($key, 'value'),
                        'setExpired' => $cache->set($key, 'value', 0),
                        'timestamp' => $cache->setByTimestamp($key, 'value', 0),
                        default => $cache->$method($key),
                    };
                    self::fail('Invalid key accepted by ' . $method);
                } catch (InvalidCacheKeyException $exception) {
                    self::assertInstanceOf(\Psr\SimpleCache\InvalidArgumentException::class, $exception);
                }
            }
        }
        self::assertSame([], glob($this->directory . '/*'));
    }

    #[DataProvider('drivers')]
    public function testBulkOperationsValidateBeforeAccessingEntries(string $driver): void
    {
        $cache = $this->cache($driver);
        $cache->set('keep', 'original');
        // Reads of expired entries also delete files, so even getMultiple must validate first.
        $cache->set('expired', 'old');
        $meta = $this->entryFile($cache, 'expired', true);
        file_put_contents($meta, (string) (time() - 1));
        foreach (['', '../escape', null, false, [], new \stdClass(), 12] as $invalid) {
            foreach (['getMultiple', 'deleteMultiple', 'setMultiple'] as $method) {
                // Numeric map keys are normalized because PHP casts numeric string array keys.
                if ($method === 'setMultiple' && is_int($invalid)) {
                    continue;
                }
                $input = (static function () use ($method, $invalid) {
                    if ($method === 'setMultiple') {
                        yield 'keep' => 'changed';
                        yield $invalid => 'invalid';
                    } else {
                        yield $method === 'getMultiple' ? 'expired' : 'keep';
                        yield $invalid;
                    }
                })();
                try {
                    $cache->$method($input);
                    self::fail('Invalid bulk key accepted by ' . $method);
                } catch (InvalidCacheKeyException) {
                    self::assertSame('original', $cache->get('keep'));
                    self::assertFileExists($meta);
                }
            }
        }
        self::assertTrue($cache->setMultiple(['0' => 'zero', '1' => 'one']));
        self::assertSame('zero', $cache->get('0'));
        self::assertSame([], $cache->getMultiple([]));
        self::assertTrue($cache->setMultiple([]));
        self::assertTrue($cache->deleteMultiple([]));
    }

    #[DataProvider('drivers')]
    public function testClearPreservesForeignFilesAndOtherNamespaces(string $driver): void
    {
        $cache = $this->cache($driver);
        $others = [];
        foreach (self::drivers() as [$other]) {
            if ($other !== $driver) {
                $others[] = $this->cache($other);
            }
        }
        $alias = $this->cache(FileCache::class);
        $alias->setCacheExtension('.cache.php');
        $others[] = $alias;
        foreach ($others as $other) {
            $other->set('same', 'other');
        }
        $cache->set('same', 'own');
        $cache->set('orphan', 'value');
        unlink($this->entryFile($cache, 'orphan'));
        $foreign = ['foreign.txt', '.hidden', 'legacy.cache.php', 'legacy.meta.txt',
            'diana-' . str_repeat('a', 64) . '-invalid.cache.php'];
        foreach ($foreign as $file) {
            file_put_contents($this->directory . '/' . $file, 'keep');
        }
        mkdir($this->directory . '/nested');
        file_put_contents($this->directory . '/nested/keep', 'keep');
        self::assertTrue($cache->clear());
        self::assertFalse($cache->has('same'));
        self::assertFileDoesNotExist($this->entryFile($cache, 'orphan', true));
        foreach ($others as $other) {
            self::assertSame('other', $other->get('same'));
        }
        foreach ($foreign as $file) {
            self::assertSame('keep', file_get_contents($this->directory . '/' . $file));
        }
        self::assertFileExists($this->directory . '/nested/keep');
        self::assertTrue($cache->clear());
    }

    #[DataProvider('drivers')]
    public function testKeysUseBoundedDistinctFileNames(string $driver): void
    {
        $cache = $this->cache($driver);
        foreach (['..', '.', '0', 'a.b_C-1', str_repeat('long', 200), 'ümlaut'] as $key) {
            self::assertTrue($cache->set($key, $key));
            self::assertSame($key, $cache->get($key));
            $path = $this->entryFile($cache, $key);
            self::assertSame($this->directory, dirname($path));
            self::assertLessThan(255, strlen(basename($path)));
        }
    }

    #[DataProvider('drivers')]
    public function testEntrySymlinksAreRejectedAndPreservedByClear(string $driver): void
    {
        $cache = $this->cache($driver);
        $target = $this->directory . '/foreign.txt';
        file_put_contents($target, 'keep');
        foreach ([false, true] as $metadata) {
            $cache->set('linked', 'value');
            $path = $this->entryFile($cache, 'linked', $metadata);
            unlink($path);
            symlink($target, $path);
            foreach (['get', 'has', 'set', 'delete'] as $method) {
                try {
                    $method === 'set' ? $cache->set('linked', 'overwrite') : $cache->$method('linked');
                    self::fail('Symbolic link accepted by ' . $method);
                } catch (\RuntimeException) {
                    self::assertSame('keep', file_get_contents($target));
                }
            }
            self::assertTrue($cache->clear());
            self::assertTrue(is_link($path));
            unlink($path);
        }
        $path = $this->entryFile($cache, 'dangling');
        symlink($this->directory . '/missing-target', $path);
        try {
            $cache->set('dangling', 'value');
            self::fail('Dangling symbolic link accepted');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($this->directory . '/missing-target');
        }
    }

    public function testExtensionsCannotIntroducePathsOrCollide(): void
    {
        $cache = $this->cache(FileCache::class);
        foreach (['', '../escape', '.cache/escape', '.cache\\escape', ".cache\0php", '.cache.*', '.'] as $extension) {
            foreach (['setCacheExtension', 'setMetaExtension'] as $method) {
                try {
                    $cache->$method($extension);
                    self::fail('Invalid extension accepted');
                } catch (\InvalidArgumentException) {
                    self::assertSame('.cache.txt', $cache->getCacheExtension());
                    self::assertSame('.meta.txt', $cache->getMetaExtension());
                }
            }
        }
        $this->expectException(\InvalidArgumentException::class);
        $cache->setMetaExtension($cache->getCacheExtension());
    }

    public function testRawPhpStringsRemainUsableAsIdeAliasFiles(): void
    {
        $cache = $this->cache(FileCache::class);
        $cache->setCacheExtension('.cache.php');
        $source = '<?php class ExampleAlias {}';
        self::assertTrue($cache->set('aliases', $source));
        self::assertSame($source, file_get_contents($this->entryFile($cache, 'aliases')));
    }
}
