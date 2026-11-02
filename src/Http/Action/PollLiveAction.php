<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Htmx;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PollPage;
use ShowOfHands\Http\Redirect;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * The poll page's <main> for its periodic refresh, rendered as the full page renders it
 * for the same member, with the panels the query keeps open. It runs on a read-only session.
 */
final readonly class PollLiveAction
{
    public function __construct(
        private PollPage $page,
        private PollRepository $polls,
    ) {}

    public static function matches(string $path): bool
    {
        return preg_match('#^/polls/[0-9]+/live$#', $path) === 1;
    }

    /**
     * @param array{id: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $member = MemberMiddleware::member($request);
        assert($member !== null);
        $poll = $this->polls->findPublished((int) $args['id']);
        // The page itself shows a poll that is gone, and a browser that opens this URL gets the page.
        if ($poll === null || !Htmx::isRequest($request)) {
            $query = $request->getUri()->getQuery();

            return Redirect::to($response, $request, '/polls/' . $args['id'] . ($query === '' ? '' : '?' . $query));
        }

        return $this->page->fragment(
            $request,
            $response,
            $poll,
            $member,
            delegationOpen: PollPage::delegationOpen($request),
            confirmClose: PollPage::confirmClose($request),
            live: true,
        );
    }
}
