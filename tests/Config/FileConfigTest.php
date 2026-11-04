<?php

namespace Diana\Tests\Config;

use Diana\Config\FileConfig;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FileConfigTest extends TestCase
{
    private string $directory;
    private string $path;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/diana-config-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->path = $this->directory . '/app.conf.php';
        file_put_contents($this->path, "<?php return ['existing' => 'keep'];\n");
        FileConfig::setDirectory($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    public function testDefaultsArePersistedAndReloadedWithoutChangingExistingValues(): void
    {
        chmod($this->path, 0640);
        $before = stat($this->path);
        $defaults = ['existing' => 'replace', 'null' => null, "a'b" => 'end\\', 'nested' => [null, false, 1.0, "\\'"]];
        $expected = array_replace($defaults, ['existing' => 'keep']);
        $config = new FileConfig('app');
        $config->addDefault($defaults);

        token_get_all(file_get_contents($this->path), TOKEN_PARSE);
        self::assertSame($expected, $config->all());
        self::assertSame($expected, (new FileConfig('app'))->all());
        self::assertSame(0640, fileperms($this->path) & 0777);
        self::assertNotSame($before['ino'], stat($this->path)['ino']);
        self::assertSame([], glob($this->directory . '/.diana-config-*'));
        $source = file_get_contents($this->path);
        $config->get('existing');
        self::assertSame($source, file_get_contents($this->path));
    }

    public function testSerializationFailureLeavesTheOriginalFileIntact(): void
    {
        $source = file_get_contents($this->path);
        $resource = fopen('php://memory', 'r');
        try {
            (new FileConfig('app'))->addDefault(['unsupported' => $resource]);
            self::fail('Expected an unsupported value to be rejected.');
        } catch (InvalidArgumentException) {
            self::assertSame($source, file_get_contents($this->path));
            self::assertSame(['existing' => 'keep'], (new FileConfig('app'))->all());
        } finally {
            fclose($resource);
        }
    }
}
