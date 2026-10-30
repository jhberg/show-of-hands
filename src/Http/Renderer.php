<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use Symfony\Component\Translation\Translator;
use Twig\Environment;

/**
 * Renders a Twig page into a response, with the locale, the URL, the signed-in member,
 * and the pending toast in the context.
 */
final readonly class Renderer
{
    public function __construct(
        private Environment $twig,
        private Translator $translator,
        private Flash $flash,
    ) {}

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
        // A page may take the toast itself, to fill in what the session must not store.
        if (!array_key_exists('toast', $data)) {
            $data['toast'] = $this->flash->take();
        }
        $html = $this->twig->render($template, $data + [
            // The translator's, because the member's saved language may replace the cookie's on the way in.
            'locale' => $this->translator->getLocale(),
            // Path and query, so the language switch keeps open panels and searches.
            'current_url' => $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery()),
            'member' => $request->getAttribute(MemberMiddleware::ATTRIBUTE),
        ]);
        $response->getBody()->write($html);

        return $response->withStatus($status)->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
}
