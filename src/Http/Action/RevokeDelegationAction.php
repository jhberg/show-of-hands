<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PollPage;
use ShowOfHands\Http\Redirect;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * Takes the member's delegated vote back without casting a ballot.
 */
final readonly class RevokeDelegationAction
{
    public function __construct(
        private PollPage $page,
        private PollRepository $polls,
        private DelegationRepository $delegations,
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

        try {
            $revoked = $this->delegations->revoke($poll->id, $member->id);
        } catch (PollNotOpenException) {
            return $this->page->answer($request, $response, $poll, $member, 'toast.poll_closed');
        }
        if (!$revoked) {
            // Taken back already, in another tab or by voting.
            return Redirect::to($response, $request, PollPage::path($poll));
        }

        return $this->page->answer($request, $response, $poll, $member, 'toast.delegation.revoked');
    }
}
