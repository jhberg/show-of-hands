<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\MembersPage;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Persistence\MemberRemover;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\RemovalTooSoonException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;

/**
 * Removes a member after the inline confirmation. They can no longer sign in, and their vote
 * drops out of the open polls. Admins cannot remove themselves, and nobody can remove a member
 * who took part in a poll closed today before the next day, or in a reopened poll while it is open.
 */
final readonly class AdminMemberDeleteAction
{
    public function __construct(
        private MembersPage $page,
        private MemberRemover $remover,
        private MemberRepository $members,
        private Clock $clock,
        private Config $config,
    ) {}

    /**
     * @param array{id: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = MemberMiddleware::member($request);
        assert($actor !== null);
        $id = (int) $args['id'];
        if ($id === $actor->id) {
            throw new HttpForbiddenException($request);
        }
        $body = $request->getParsedBody();
        $query = MembersPage::query(is_array($body) ? ($body['q'] ?? null) : null);
        try {
            $removed = $this->remover->remove($id, $this->clock->now(), new \DateTimeZone($this->config->timezone));
        } catch (RemovalTooSoonException $e) {
            $member = $this->members->find($id);
            assert($member !== null);
            $toastKey = $e->reopened ? 'toast.member.remove_after_reopened' : 'toast.member.remove_tomorrow';

            return $this->page->answer($request, $response, $actor, $query, $toastKey, ['name' => $member->name]);
        }
        if ($removed === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->page->answer($request, $response, $actor, $query, 'toast.member.removed', ['name' => $removed->name]);
    }
}
