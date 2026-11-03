<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\AdminPollsPage;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * Deletes a draft after the inline confirmation. A published poll is never deleted by hand.
 */
final readonly class AdminPollDeleteAction
{
    public function __construct(
        private AdminPollsPage $page,
        private PollRepository $polls,
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
        if (!$this->polls->deleteDraft($poll->id)) {
            return $this->page->answer($request, $response, $poll->id, 'toast.poll.stale');
        }

        return $this->page->redirect($request, $response, AdminPollsPage::PATH, 'toast.poll.deleted');
    }
}
