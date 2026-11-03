<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\AdminPollsPage;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * Publishes a draft, so members can vote. A poll that is no draft any more stays as it is.
 */
final readonly class AdminPollPublishAction
{
    public function __construct(
        private AdminPollsPage $page,
        private PollRepository $polls,
        private Clock $clock,
    ) {}

    /**
     * @param array{id: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $poll = $this->polls->find((int) $args['id']);
        if ($poll === null) {
            return $this->page->missing($request, $response);
        }
        $published = $this->polls->publish($poll->id, $this->clock->now());

        return $this->page->answer($request, $response, $poll->id, $published ? 'toast.poll.published' : 'toast.poll.stale');
    }
}
