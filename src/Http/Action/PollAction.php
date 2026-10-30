<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\PollPage;
use ShowOfHands\Infrastructure\Persistence\PollRepository;

/**
 * The poll page that every member reaches through the poll's link. An htmx request, such as
 * opening the delegation panel, gets only <main>.
 */
final readonly class PollAction
{
    public function __construct(
        private PollPage $page,
        private PollRepository $polls,
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

        if ($request->getHeaderLine('HX-Request') === 'true' && $request->getHeaderLine('HX-History-Restore-Request') !== 'true') {
            return $this->page->fragment($request, $response, $poll, $member, delegationOpen: PollPage::delegationOpen($request));
        }

        return $this->page->page($request, $response, $poll, $member);
    }
}
