## Diana PHP Framework

Requires PHP **8.5.12 or newer within PHP 8**. Composer enforces `^8.5.12`.

There are plenty of frameworks to choose out there, so which one to go for?
Or should you rather use plain PHP for the next project, because of all of that unnecessary bloat you don't even need?
With that in mind, I have been starting to develop Diana, a modular, simple to use, yet powerful framework. Diana aims to be the perfect choice for every project, because you just include the parts you need for your specific app, leaving out all of the bloat that you don't need.

The target group of Diana are developers loving simplicity and easy-to-understand code. Diana will offer many pre-built packages which you are free to use, but you can always decide to extend those or even write your own packages if you wish to do so, integrating every possible existing library. This freedom of choosing and the variety of packages provides a whole new set of possibilities.

## Official Documentation

A full documentation of the framework will be available at a future time.

## Entry point and global middleware

The framework processes a request through global middleware and delegates to the
class configured as `entrypoint`. That class must provide a public `__invoke()`
method. It is constructed once during boot, registered as a package and called
through the container for each request. Additional method dependencies are
injected by the container.

```php
use Diana\Contracts\RequestContract;
use Diana\IO\Response;

class ExampleEntryPoint
{
    public function __invoke(RequestContract $request): Response
    {
        return new Response('Hello', $request->getDefaultStatusCode());
    }
}
```

Configure the class and global middleware in `framework.conf.php`:

```php
return [
    'entrypoint' => ExampleEntryPoint::class,
    'middleware' => [ExampleMiddleware::class],
    'packages' => [],
];
```

Middleware classes expose `run($request, $next, $output, $data)`; parameters not
needed by a middleware may be omitted. Closures are also supported. Call
`return $next()` to continue with the current request, or `return $next($request)`
to forward a replacement request. Later middleware, routing and controller
injection receive that replacement. A replacement in route middleware keeps the
already resolved route; it does not trigger routing again.
Return a `Response` without calling `$next` to stop processing. Later middleware
is not instantiated and the controller is not called.
Global middleware wraps the entry point, including its response and error
fallbacks. A returned `Response` keeps its identity and status; other entry point
results are wrapped with the request's default status.

```php
class ExampleMiddleware
{
    public function run(RequestContract $request, \Closure $next): Response
    {
        if (!$this->isAllowed($request)) {
            return new Response('Forbidden', 403);
        }

        $response = $next();
        // Optionally change the response after downstream processing.
        return $response;
    }

    private function isAllowed(RequestContract $request): bool
    {
        // Apply your application's authorization rules here.
        return true;
    }
}
```

Middleware runs in declaration order. Responses return through middleware that
already called `$next`, in reverse order, including responses from an abort.

The framework has no routing dependency, bindings or dispatch logic. Optional
packages supply their own entry points, which an application can delegate to.
The blank project's `AppModule` provides this composition.

## Package modules

An optional package integration belongs in its `Framework/<Package>Module.php`.
Modules are ordinary classes, just like controllers; no base class or interface
is required. Register them before classes depending on their services:

```php
$module = $app->registerPackage(ExampleModule::class);
$app->registerPackage(ExampleController::class);
```

`registerPackage()` returns the registered object. Repeated registration reuses
it and does not dispatch another package event unless `force` is requested.
A configured container binding is still registered on the first call, so its
event listeners or controller methods can be discovered. Overrides of
`registerPackage()` must use the new `object` return type.

A module's constructor can install its package's defaults and lifecycle hooks.
Its methods may also carry controller attributes. `__invoke()` is needed only
when a module serves as an entry point or callable delegate. Packages remain
usable directly without instantiating their framework module.

## Pipeline and boot configuration

`Diana\Contracts\Core\Pipeline` lives in the SDK. The framework binds it to
`Diana\IO\Pipeline` through the `bindings` configuration. These bindings create
a fresh instance on each resolution, while `singleton` bindings reuse instances.
Custom implementations of the SDK container must implement `bind()` as well as
`singleton()`.

The generic pipeline executes each `pipe([...])` call immediately. Put middleware
and its terminal handler in the same call so that `$next` controls the entire
chain. Separate `pipe()` calls are independent executions. The end of a chain
returns the initial output from `send($input, $output, $data)`; an empty list
preserves it. Each middleware receives `($input, $next, $output, $data)`.

Boot order is: container setup, kernel modules, dispatcher, configured
`packages`, entry point package, then `BootEvent`. Entry point constructor dependencies
are initialized before its nested package registrations. `generateResponse()`
and `runCommand()` return a response; `handleRequest()` additionally emits it
and shuts down the application.

The lifecycle messages `BootEvent`, `RegisterPackageEvent` and `ShutdownEvent`
belong to this package under `Diana\Events`. Optional framework modules may use
these events; the SDK contains the shared contracts.

## File cache paths

`FileCache`, `JsonFileCache` and `PhpFileCache` use hashed filenames in a namespace
derived from the driver class and its data/metadata extensions. Drivers and the
IDE alias cache therefore do not share entries or expiration metadata. Changing
an extension selects a different namespace. Extensions must be distinct,
dot-separated alphanumeric suffixes such as `.cache.php` and `.meta.txt`.

Keys must be non-empty strings without control characters or `{ } ( ) / \\ @ :`.
Invalid keys throw `Diana\Cache\Exceptions\InvalidCacheKeyException`, which
implements the PSR Simple Cache invalid-argument interface. Bulk operations
validate the entire iterable before accessing entries. `setMultiple()` also
accepts integer map keys as their string equivalents because PHP converts
numeric string array keys to integers. Keys in `getMultiple()` and
`deleteMultiple()` must be strings.

`clear()` removes only regular files matching the current namespace and its
exact data/metadata filename patterns. It preserves other files, directories,
symlinks and other driver namespaces. Entry paths pointing to symlinks or
directories are rejected with a `RuntimeException` during normal operations.

Existing caches using raw keys as filenames are ignored and preserved, so entries
are rebuilt on demand. Remove obsolete files separately after deployment. IDE
alias files still contain raw PHP source and retain the `.cache.php` suffix,
but now have hashed names too.

The configured cache directories must be controlled by the application and must
not be writable by untrusted users. The link checks do not protect against
concurrent filesystem manipulation by another process with write access. PHP
cache files execute code, and all three drivers can deserialize PHP objects;
cache storage is a trusted boundary. Atomic persistence and concurrent writers
are separate concerns.

## Errors and Bugs

If you find any bugs or errors, please report them on the official forums that will be available at a future time.
Feel free to also send an e-mail to an email address, that will be provided at a future time. Please keep in mind that the Diana framework is still in development and can contain errors. I try to do my best to fix them in time. 

Also, please report any security vulnerability to the given address.
