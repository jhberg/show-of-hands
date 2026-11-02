<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\AdminPollsPage;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * A poll's detail in the admin area. ?confirm=delete and ?confirm=reopen open the confirmations.
 */
final readonly class AdminPollAction
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

        return $this->page->detail($request, $response, $poll, AdminPollsPage::confirm($request));
    }
}
