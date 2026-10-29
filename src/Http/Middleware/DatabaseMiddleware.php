<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Maintenance\Maintenance;
use ShowOfHands\Infrastructure\Setup\FirstStart;

/**
 * Prepares the database before a request uses it. Hosts without a shell or cron have no
 * other place for this work:
 *
 * - runs pending migrations on the first request after an update,
 * - fills a new installation (default voice groups, first admin),
 * - runs lazy maintenance at most once an hour.
 */
final readonly class DatabaseMiddleware implements MiddlewareInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== ThemeAction::PATH) {
            // Resolved lazily, so the stylesheet never opens the database.
            $this->resolve(Migrator::class)->migrate();
            $this->resolve(FirstStart::class)->run();
            $this->resolve(Maintenance::class)->runIfDue();
        }

        return $handler->handle($request);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function resolve(string $class): object
    {
        $service = $this->container->get($class);
        assert($service instanceof $class);

        return $service;
    }
}
