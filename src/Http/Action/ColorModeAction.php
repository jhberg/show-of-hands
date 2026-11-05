<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\ColorMode;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PreferenceCookie;
use ShowOfHands\Http\ReturnPath;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;

/**
 * The colour mode switch: stores the choice in a cookie, and for a signed-in member also on
 * the member, then returns to the page it came from.
 */
final readonly class ColorModeAction
{
    public const COOKIE = 'soh_mode';

    public function __construct(
        private ReturnPath $returnPath,
        private MemberRepository $members,
        private bool $https,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $redirect = $response->withStatus(303)->withHeader('Location', $this->returnPath->sanitize($body['return'] ?? null));
        $mode = ColorMode::tryFrom(is_string($body['mode'] ?? null) ? $body['mode'] : '');
        if ($mode === null) {
            return $redirect;
        }

        $member = MemberMiddleware::member($request);
        if ($member !== null) {
            $this->members->setColorMode($member->id, $mode);
        }

        return $redirect->withAddedHeader('Set-Cookie', PreferenceCookie::header(self::COOKIE, $mode->value, $this->https));
    }
}
