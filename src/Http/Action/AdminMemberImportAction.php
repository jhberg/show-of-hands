<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\MembersPage;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\MemberImporter;

/**
 * Reads the member file again after the confirmation that showed what it adds. Members already
 * on the list stay as they are; the toast counts the new ones and the rows left out.
 */
final readonly class AdminMemberImportAction
{
    public function __construct(
        private MembersPage $page,
        private MemberImporter $importer,
        private Clock $clock,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $actor = MemberMiddleware::member($request);
        assert($actor !== null);
        $body = $request->getParsedBody();
        $query = MembersPage::query(is_array($body) ? ($body['q'] ?? null) : null);

        $import = $this->importer->import($this->clock->now());
        if ($import === null) {
            return $this->page->answer($request, $response, $actor, $query, 'toast.member.import_missing', ['file' => $this->importer->fileName()]);
        }
        $toastKey = $import->problems === [] ? 'toast.member.imported' : 'toast.member.imported_with_problems';

        return $this->page->answer($request, $response, $actor, $query, $toastKey, ['count' => count($import->added), 'problems' => count($import->problems)]);
    }
}
