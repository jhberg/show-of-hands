<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use Twig\Environment;

/**
 * Renders a Twig page into a response, with the request's locale and URL in the context.
 */
final readonly class Renderer
{
    public function __construct(private Environment $twig) {}

    /**
     * @param array<string, mixed> $data
     */
    public function render(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $template,
        array $data = [],
        int $status = 200,
    ): ResponseInterface {
        $uri = $request->getUri();
        $html = $this->twig->render($template, $data + [
            'locale' => $request->getAttribute(LocaleMiddleware::ATTRIBUTE),
            // Path and query, so the language switch keeps open panels and searches.
            'current_url' => $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()),
            'toast' => null,
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
