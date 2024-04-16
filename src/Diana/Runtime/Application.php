<?php

namespace Diana\Runtime;

use Closure;
use Composer\Autoload\ClassLoader;

use Diana\IO\Request;

use Diana\IO\Contracts\Kernel;

use Diana\Runtime\Contracts\Configurable;
use Diana\Runtime\Implementations\Config;
use Diana\Support\Collection\Collection;
use Diana\Support\Helpers\Filesystem;

class Application extends Container implements Configurable
{
    use Config;

    protected Collection $paths;

    public function __construct(protected string $path, protected ClassLoader $classLoader)
    {
        $this->paths = (new Collection(['app' => $path, 'framework' => dirname(dirname(dirname(__DIR__)))]));

        Filesystem::setBasePath($path);

        $this->loadConfig();
        $this->setExceptionHandler();
        $this->registerBindings();
        $this->provideAliases();
    }

    public function &getPaths(): Collection
    {
        return $this->paths;
    }

    public function getConfigDefault(): array
    {
        return [
            'aliasCachePath' => './tmp/aliases.php',
            'aliases' => [],
            'drivers' => [
                \Diana\IO\Contracts\Kernel::class => \Diana\IO\Kernel::class,
                \Diana\Routing\Contracts\Router::class => \Diana\Routing\Drivers\FileRouter::class,
            ],
            'entryPoint' => \App\AppPackage::class,
            'env' => 'dev',
            'logs' => [
                'error' => './logs/error.log',
                'access' => './logs/access.log'
            ],
            'routeCachePath' => './tmp/routes.php',
            'timezone' => 'Europe/Berlin'
        ];
    }

    protected function setExceptionHandler(): void
    {
        error_reporting(E_ALL);

        ini_set('display_errors', $this->config->env == 'dev' ? 'On' : 'Off');
        ini_set('log_errors', 'On');
        ini_set('error_log', Filesystem::absPath($this->config->logs['error']));
        ini_set('access_log', Filesystem::absPath($this->config->logs['access']));
        ini_set('date.timezone', $this->config->timezone);

        ini_set('xdebug.var_display_max_depth', 10);
        ini_set('xdebug.var_display_max_children', 256);
        ini_set('xdebug.var_display_max_data', 1024);
        //ini_set('xdebug.max_nesting_level', 9999);

        $whoops = new \Whoops\Run;
        $whoops->pushHandler(php_sapi_name() == 'cli' ? new \Whoops\Handler\PlainTextHandler : new \Whoops\Handler\PrettyPageHandler);
        $whoops->register();
    }

    protected function registerBindings(): void
    {
        static::setInstance($this);
        $this->instance(Application::class, $this);
        $this->instance(Container::class, $this);
        $this->instance(ClassLoader::class, $this->classLoader);

        foreach ($this->config->drivers as $name => $driver)
            $this->singleton($name, $driver);
    }

    protected function provideAliases()
    {
        // cache ide helpers
        // TODO: make this a command
        if (!file_exists($cachePath = Filesystem::absPath($this->config->aliasCachePath))) {
            $cache = "<?php" . str_repeat(PHP_EOL, 2);

            foreach ($this->config->aliases as $class)
                $cache .= "class " . substr($class, strrpos($class, '\\') + 1) . " extends $class {}" . PHP_EOL;

            file_put_contents($cachePath, $cache);
        }

        // provide aliases
        foreach ($this->config->aliases as $class)
            class_alias($class, substr($class, strrpos($class, '\\') + 1));
    }

    public function handleRequest(Request $request): void
    {
        $kernel = $this->resolve(Kernel::class);
        $kernel->boot($request, $this->config->entryPoint, $this->config->routeCachePath);
        $response = $kernel->handle($request);
        $response->emit();
        $kernel->terminate($request, $response);
    }
}