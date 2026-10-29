<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ShowOfHands\Infrastructure\I18n\Locales;
use Symfony\Component\Translation\Translator;

/**
 * Picks the request's locale: the language cookie, then the configured default.
 * A signed-in member's saved choice will come first once members exist.
 */
final readonly class LocaleMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'locale';
    public const COOKIE = 'soh_locale';

    public function __construct(
        private Translator $translator,
        private string $defaultLocale,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $cookie = $request->getCookieParams()[self::COOKIE] ?? null;
        $locale = Locales::isSupported($cookie) ? $cookie : $this->defaultLocale;
        $this->translator->setLocale($locale);

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $locale));
    }
}
