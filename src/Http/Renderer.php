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

    /**
     * An admin page or, for htmx, one of its panels. The toast goes into the page, or into
     * HX-Trigger for a panel, because the toast region lies outside it.
     *
     * @param array<string, mixed> $data
     */
    public function panel(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $page,
        string $fragment,
        array $data,
        ?string $toast,
        int $status = 200,
    ): ResponseInterface {
        if (!Htmx::isRequest($request)) {
            // Without a toast of its own, the page takes the flash.
            return $this->render($request, $response, $page, $data + ($toast === null ? [] : ['toast' => $toast]), $status);
        }
        if ($toast !== null) {
            $response = $response->withHeader('HX-Trigger', json_encode(['toast' => $toast], JSON_THROW_ON_ERROR));
        }

        // A null toast keeps a pending flash, which a fragment cannot show.
        return $this->render($request, $response, $fragment, $data + ['toast' => null], $status);
    }
}
