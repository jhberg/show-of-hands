<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\MembersPage;
use ShowOfHands\Http\Middleware\MemberMiddleware;

/**
 * The member list. ?q= searches, ?add=1 and ?edit={id} open the inline form, ?delete={id} the
 * confirmation, ?import=1 the import's preview, so each works without JavaScript. With htmx, the
 * same GET swaps only the panel.
 */
final readonly class AdminMembersAction
{
    public function __construct(private MembersPage $page) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = MemberMiddleware::member($request);
        assert($actor !== null);

        return $this->page->show(
            $request,
            $response,
            $actor,
            MembersPage::query($request->getQueryParams()['q'] ?? null),
            $this->page->formFor($request),
            MembersPage::deleting($request),
            MembersPage::importing($request),
        );
    }
}
