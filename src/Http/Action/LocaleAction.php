<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PreferenceCookie;
use ShowOfHands\Http\ReturnPath;
use ShowOfHands\Infrastructure\I18n\Locales;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;

/**
 * The language switch: stores the choice in a cookie, and for a signed-in member also on
 * the member, then returns to the page it came from.
 */
final readonly class LocaleAction
{
    public function __construct(
        private ReturnPath $returnPath,
        private MemberRepository $members,
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

        $member = MemberMiddleware::member($request);
        if ($member !== null) {
            $this->members->setLocale($member->id, $locale);
        }

        return $redirect->withAddedHeader('Set-Cookie', PreferenceCookie::header(LocaleMiddleware::COOKIE, $locale, $this->https));
    }
}
