<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PollPage;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\PollCloser;
use ShowOfHands\Infrastructure\Persistence\PollNotOpenException;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * A board member closes the poll on its page. The result then replaces the ballot for everyone.
 */
final readonly class CloseAction
{
    public function __construct(
        private PollPage $page,
        private PollRepository $polls,
        private PollCloser $closer,
        private Clock $clock,
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
            $this->closer->close($poll->id, $this->clock->now());
            $toastKey = 'toast.close_done';
        } catch (PollNotOpenException) {
            // Another board member was quicker.
            $toastKey = 'toast.poll_closed';
        }

        return $this->page->answer($request, $response, $poll, $member, $toastKey);
    }
}
