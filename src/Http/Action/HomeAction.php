<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Nobody can sign in yet, so the home page sends every visitor to the login page.
 */
final readonly class HomeAction
{
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $response->withStatus(303)->withHeader('Location', '/login');
    }
}
