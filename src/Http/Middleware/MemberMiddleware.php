<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ShowOfHands\Domain\Member;
use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Http\Session;
use ShowOfHands\Infrastructure\I18n\Locales;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use Symfony\Component\Translation\Translator;

/**
 * Loads the signed-in member on every request, so a changed role or a removed member takes effect
 * at once. The address they signed in with must still be theirs: an admin who removes it, or moves
 * it to another member, ends the session. The member's saved language replaces the cookie's.
 */
final readonly class MemberMiddleware implements MiddlewareInterface
{
    public const ATTRIBUTE = 'member';
    public const SESSION_KEY = 'member_id';

    /** The address the member signed in with. */
    public const EMAIL_KEY = 'member_email';

    public function __construct(
        private ContainerInterface $container,
        private Session $session,
        private Translator $translator,
    ) {}

    public static function member(ServerRequestInterface $request): ?Member
    {
        $member = $request->getAttribute(self::ATTRIBUTE);

        return $member instanceof Member ? $member : null;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // The stylesheet is public and cached, so it must not open a session.
        if ($request->getUri()->getPath() === ThemeAction::PATH) {
            return $handler->handle($request);
        }
        $id = $this->session->get(self::SESSION_KEY);
        if (!is_int($id)) {
            return $handler->handle($request);
        }
        $members = $this->container->get(MemberRepository::class);
        assert($members instanceof MemberRepository);
        $email = $this->session->get(self::EMAIL_KEY);
        $member = is_string($email) ? $members->findByEmail($email)[0] ?? null : null;
        if ($member === null || $member->id !== $id) {
            $this->session->clear();

            return $handler->handle($request);
        }
        if (Locales::isSupported($member->locale)) {
            $this->translator->setLocale($member->locale);
        }

        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $member));
    }
}
