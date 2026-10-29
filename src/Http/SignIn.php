<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use ShowOfHands\Domain\EmailAddress;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;

/**
 * Signs a visitor in by email address, for Google and the dev login alike.
 */
final readonly class SignIn
{
    private const ERROR_KEY = 'login_error';

    public function __construct(
        private Session $session,
        private MemberRepository $members,
        private Flash $flash,
    ) {}

    /**
     * @param bool $verified whether Google confirmed that the account owns the address
     *
     * @return bool false when the address belongs to no member; the login page then shows it
     */
    public function withEmail(string $email, bool $verified = true): bool
    {
        $match = $verified ? $this->members->findByEmail($email) : null;
        if ($match === null) {
            // Whoever was signed in before tried another account, so they are signed out now.
            $this->session->clear();
            $this->session->set(self::ERROR_KEY, $email);

            return false;
        }
        [$member, $secondAddress] = $match;
        // A session id that existed before sign-in must not carry the signed-in member.
        $this->session->regenerate();
        $this->session->set(MemberMiddleware::SESSION_KEY, $member->id);
        if ($secondAddress) {
            $this->flash->toast('toast.signed_in_via', ['name' => $member->name, 'address' => (string) EmailAddress::normalize($email)]);
        } else {
            $this->flash->toast('toast.signed_in', ['name' => $member->name]);
        }

        return true;
    }

    /**
     * The address of the last failed attempt, removed from the session.
     */
    public function takeError(): ?string
    {
        $email = $this->session->take(self::ERROR_KEY);

        return is_string($email) ? $email : null;
    }

    public function signOut(): void
    {
        $this->session->clear();
    }
}
