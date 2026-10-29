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

/**
 * Brings the database schema up to date on the first request after an update.
 * Hosts without a shell have no other place to run migrations.
 */
final readonly class MigrateMiddleware implements MiddlewareInterface
{
    public function __construct(private ContainerInterface $container) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getUri()->getPath() !== ThemeAction::PATH) {
            // Resolved lazily, so the stylesheet never opens the database.
            $migrator = $this->container->get(Migrator::class);
            assert($migrator instanceof Migrator);
            $migrator->migrate();
        }

        return $handler->handle($request);
    }
}
