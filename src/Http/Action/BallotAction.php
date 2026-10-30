<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\CastOutcome;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PollPage;
use ShowOfHands\Http\Redirect;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * Casts or changes the member's ballot, and takes back a delegated vote. The choice arrives in
 * the POST body only.
 */
final readonly class BallotAction
{
    public function __construct(
        private PollPage $page,
        private PollRepository $polls,
        private BallotRepository $ballots,
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
        $body = $request->getParsedBody();
        $choice = Choice::tryFrom(is_array($body) && is_string($body['choice'] ?? null) ? $body['choice'] : '');
        if ($choice === null) {
            return Redirect::to($response, $request, PollPage::path($poll));
        }

        $params = [];
        try {
            $cast = $this->ballots->cast($poll->id, $member->id, $choice);
            $toastKey = match ($cast->outcome) {
                CastOutcome::Registered => 'toast.ballot.registered',
                CastOutcome::Changed => 'toast.ballot.changed',
                CastOutcome::Unchanged => 'toast.ballot.unchanged',
            };
            $holder = $cast->reclaimedFrom === null ? null : $this->members->find($cast->reclaimedFrom);
            if ($holder !== null) {
                $toastKey = 'toast.ballot.reclaimed';
                $params = ['name' => $holder->firstName()];
            }
        } catch (PollNotOpenException) {
            $toastKey = 'toast.poll_closed';
        }

        return $this->page->answer($request, $response, $poll, $member, $toastKey, $params);
    }
}
