<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Slim\Interfaces\RouteResolverInterface;
use Slim\Routing\RoutingResults;

/**
 * Validates a path to send the visitor back to. Only paths of this app's own GET routes pass,
 * so a crafted link cannot redirect to another site.
 */
final readonly class ReturnPath
{
    public function __construct(private RouteResolverInterface $routes) {}

    public function sanitize(mixed $path, string $fallback = '/'): string
    {
        if (!is_string($path) || preg_match('#^/(?![/\\\\])[^\s]*$#', $path) !== 1) {
            return $fallback;
        }
        $route = (string) parse_url($path, PHP_URL_PATH);
        $result = $this->routes->computeRoutingResults($route, 'GET');

        return $result->getRouteStatus() === RoutingResults::FOUND ? $path : $fallback;
    }
}
