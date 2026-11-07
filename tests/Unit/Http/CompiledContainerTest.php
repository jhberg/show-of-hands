<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Http\AppFactory;
use ShowOfHands\Http\ErrorHandler;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Http\Middleware\DatabaseMiddleware;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\Middleware\SecurityHeadersMiddleware;
use ShowOfHands\Infrastructure\Config\ConfigLoader;
use ShowOfHands\Infrastructure\Maintenance\Maintenance;
use ShowOfHands\Paths;
use ShowOfHands\Tests\TempDirectory;
use Slim\Interfaces\RouteResolverInterface;

/**
 * A release compiles the service container once. Every route and middleware must resolve from
 * the compiled one, which runs closures without what they could capture.
 */
final class CompiledContainerTest extends TestCase
{
    use TempDirectory;

    public function testEveryRouteAndMiddlewareResolvesFromTheCompiledContainer(): void
    {
        $paths = new Paths(dirname(__DIR__, 3), $this->tempDir() . '/data', $this->tempDir() . '/config.php');
        $config = ConfigLoader::fromArray([
            'app' => ['url' => 'http://localhost:8080', 'secret' => str_repeat('ab', 32), 'timezone' => 'Europe/Berlin', 'env' => 'prod'],
            'google' => ['client_id' => 'id', 'client_secret' => 'secret'],
            'brand' => ['name' => 'Example Choir'],
            // As config.example.php ships it: an object, which a compiled container cannot hold.
            'bootstrap_admin' => ['name' => 'Alex Example', 'email' => 'alex@example.org'],
        ]);
        $compileTo = $this->tempDir() . '/container';
        /** @var list<class-string> $services */
        $services = [ErrorHandler::class, CsrfMiddleware::class, MemberMiddleware::class, DatabaseMiddleware::class, LocaleMiddleware::class, SecurityHeadersMiddleware::class, Maintenance::class];
        $app = AppFactory::create($paths, $config);
        foreach ($app->getRouteCollector()->getRoutes() as $route) {
            $handler = $route->getCallable();
            self::assertIsString($handler);
            assert(class_exists($handler));
            $services[] = $handler;
        }

        // The second build loads what the first compiled.
        foreach ([1, 2] as $build) {
            $container = AppFactory::container($paths, $config, compileTo: $compileTo);
            // As AppFactory::create does once Slim exists.
            $container->set(RouteResolverInterface::class, $app->getRouteResolver());
            foreach (array_unique($services) as $service) {
                self::assertInstanceOf($service, $container->get($service), "{$service} in build {$build}");
            }
        }
        self::assertCount(1, glob($compileTo . '/CompiledContainer*.php') ?: []);
    }
}
