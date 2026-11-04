<?php

// Run by HttpResponseTest through PHP's HTTP server, never included by PHPUnit.
require dirname(__DIR__, 3) . '/vendor/autoload.php';

use Composer\Autoload\ClassLoader;
use Diana\Config\Config;
use Diana\Framework\Core\Application;
use Diana\IO\HttpRequest;
use Diana\IO\Response;
use Diana\Router\Attributes\Get;
use Diana\Router\Framework\RouterModule;

class HttpResponseController
{
    #[Get('/created')]
    public function created(): Response
    {
        return new Response(['created' => true], 201, [
            'Location' => '/resource/1',
            'X-Custom' => 'preserved',
            'Set-Cookie' => ['first=1; Path=/', 'second=2; Path=/'],
        ]);
    }

    #[Get('/data')]
    public function data(): array { return ['ok' => true]; }

    #[Get('/redirect')]
    public function redirect(): Response { return new Response('', 307, ['Location' => '/data']); }
}

class HttpResponseConfig extends Config
{
    public function __construct()
    {
        parent::__construct('framework');
        $this->config = [
            'entrypoint' => RouterModule::class,
            'packages' => [RouterModule::class, HttpResponseController::class],
        ];
    }

    public function addDefault(array $default): self
    {
        parent::addDefault($default);
        $this->config = $this->arrayMergeRecursiveDistinct($default, $this->config);
        return $this;
    }
}

$app = new class(__DIR__, new ClassLoader(), new HttpResponseConfig()) extends Application {
    protected array $modules = [];
};
$app->handleRequest(new HttpRequest($_SERVER['REQUEST_URI']));
