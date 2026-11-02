<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\ReopenRefusal;
use ShowOfHands\Http\AdminPollsPage;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Persistence\PollReopener;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * Reopens a poll closed by mistake, after the inline confirmation, on the day it closed only.
 * Every member then sees on the poll page that it was reopened.
 */
final readonly class AdminPollReopenAction
{
    public function __construct(
        private AdminPollsPage $page,
        private PollRepository $polls,
        private PollReopener $reopener,
        private Clock $clock,
        private Config $config,
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
        $toastKey = match ($this->reopener->reopen($poll->id, $this->clock->now(), new \DateTimeZone($this->config->timezone))) {
            null => 'toast.poll.reopened',
            ReopenRefusal::TooLate => 'toast.poll.reopen_too_late',
            ReopenRefusal::NotClosed => 'toast.poll.stale',
        };

        return $this->page->answer($request, $response, $poll->id, $toastKey);
    }
}
