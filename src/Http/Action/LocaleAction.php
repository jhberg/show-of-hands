<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\ReturnPath;
use ShowOfHands\Infrastructure\I18n\Locales;

/**
 * The language switch: stores the choice in a cookie and returns to the page it came from.
 */
final readonly class LocaleAction
{
    private const COOKIE_DAYS = 365;

    public function __construct(
        private ReturnPath $returnPath,
        private bool $https,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $redirect = $response->withStatus(303)->withHeader('Location', $this->returnPath->sanitize($body['return'] ?? null));
        $locale = $body['locale'] ?? null;
        if (!Locales::isSupported($locale)) {
            return $redirect;
        }

        $cookie = sprintf(
            '%s=%s; Path=/; Max-Age=%d; HttpOnly; SameSite=Lax%s',
            LocaleMiddleware::COOKIE,
            $locale,
            self::COOKIE_DAYS * 86400,
            $this->https ? '; Secure' : '',
        );

        return $redirect->withAddedHeader('Set-Cookie', $cookie);
    }
}
