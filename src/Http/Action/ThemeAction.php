<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Infrastructure\Theme\Theme;

/**
 * Serves /theme.css, the colour tokens and font derived from the brand configuration.
 */
final readonly class ThemeAction
{
    public const PATH = '/theme.css';

    public function __construct(
        private Theme $theme,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $response->getBody()->write($this->theme->css());

        // Pages link the stylesheet with ?v=<version>, and a changed brand changes the version.
        // The URL therefore never serves other content, and browsers may keep it.
        return $response
            ->withHeader('Content-Type', 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=31536000, immutable');
    }
}
