<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Sends the security headers on every response the app renders, error pages included.
 *
 * The CSP allows no inline script, no inline style, and no eval. Every page loads its
 * scripts, styles, and fonts from this origin only.
 */
final readonly class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
        . "font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; "
        . "base-uri 'none'; object-src 'none'";

    public function __construct(private bool $https) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request)
            ->withHeader('Content-Security-Policy', self::CSP)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('X-Frame-Options', 'DENY')
            ->withHeader('Referrer-Policy', 'same-origin')
            ->withHeader('Cross-Origin-Opener-Policy', 'same-origin')
            ->withHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        if ($this->https) {
            $response = $response->withHeader('Strict-Transport-Security', 'max-age=31536000');
        }
        // Pages may show who voted and what the member chose, so no cache keeps a copy.
        // Responses that are safe to cache, like the theme, set their own header.
        if (!$response->hasHeader('Cache-Control')) {
            $response = $response->withHeader('Cache-Control', 'no-store');
        }

        return $response;
    }
}
