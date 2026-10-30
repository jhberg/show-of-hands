<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PollPage;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * Hands the member's vote to another member. The repository enforces every rule, so a stale
 * page that still offers a member who is no longer free gets a refusal, not a delegation.
 */
final readonly class DelegationAction
{
    public function __construct(
        private PollPage $page,
        private PollRepository $polls,
        private DelegationRepository $delegations,
        private MemberRepository $members,
    ) {}

    /**
     * @param array{id: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $member = MemberMiddleware::member($request);
        assert($member !== null);
        $poll = $this->polls->findPublished((int) $args['id']);
        if ($poll === null) {
            return $this->page->missing($request, $response);
        }
        if (!$poll->isOpen()) {
            return $this->page->answer($request, $response, $poll, $member, 'toast.poll_closed');
        }
        $body = $request->getParsedBody();
        $to = is_array($body) && is_string($body['to'] ?? null) && ctype_digit($body['to']) ? (int) $body['to'] : null;
        if ($to === null) {
            return $this->page->answer($request, $response, $poll, $member, 'toast.delegation.choose', delegationOpen: true);
        }

        try {
            if ($this->delegations->delegate($poll->id, $member->id, $to) !== null) {
                return $this->page->answer($request, $response, $poll, $member, 'toast.delegation.refused', delegationOpen: true);
            }
        } catch (PollNotOpenException) {
            return $this->page->answer($request, $response, $poll, $member, 'toast.poll_closed');
        }
        $recipient = $this->members->find($to);
        assert($recipient !== null);

        return $this->page->answer($request, $response, $poll, $member, 'toast.delegation.done', ['name' => $recipient->name]);
    }
}
