<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\CastOutcome;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Http\Flash;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PollPage;
use ShowOfHands\Http\Redirect;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * Casts or changes the member's ballot. The choice arrives in the POST body only.
 */
final readonly class BallotAction
{
    public function __construct(
        private PollPage $page,
        private PollRepository $polls,
        private BallotRepository $ballots,
        private Flash $flash,
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
        $location = '/polls/' . $poll->id;
        if ($choice === null) {
            return Redirect::to($response, $request, $location);
        }

        try {
            $toastKey = match ($this->ballots->cast($poll->id, $member->id, $choice)) {
                CastOutcome::Registered => 'toast.ballot.registered',
                CastOutcome::Changed => 'toast.ballot.changed',
                CastOutcome::Unchanged => 'toast.ballot.unchanged',
            };
        } catch (PollNotOpenException) {
            $toastKey = 'toast.poll_closed';
        }

        if ($request->getHeaderLine('HX-Request') === 'true') {
            // Re-read, because the poll may have closed in the meantime.
            $poll = $this->polls->findPublished($poll->id);
            if ($poll === null) {
                return $this->page->missing($request, $response);
            }

            return $this->page->fragment($request, $response, $poll, $member, $toastKey);
        }
        $this->flash->toast($toastKey);

        return Redirect::to($response, $request, $location);
    }
}
