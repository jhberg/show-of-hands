<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\AdminPollsPage;

/**
 * The admin area's poll list.
 */
final readonly class AdminPollsAction
{
    public function __construct(private AdminPollsPage $page) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page->list($request, $response);
    }
}
