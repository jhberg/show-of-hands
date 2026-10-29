<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class Redirect
{
    /**
     * A 303 redirect. htmx would follow it inside the swapped element, so an htmx request
     * gets HX-Redirect instead, which makes the browser load the whole page.
     */
    public static function to(ResponseInterface $response, ServerRequestInterface $request, string $location): ResponseInterface
    {
        if ($request->getHeaderLine('HX-Request') === 'true') {
            return $response->withStatus(200)->withHeader('HX-Redirect', $location);
        }

        return $response->withStatus(303)->withHeader('Location', $location);
    }
}
