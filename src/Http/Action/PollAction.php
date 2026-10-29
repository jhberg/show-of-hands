<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Renderer;

/**
 * The poll page. No poll exists yet, so every link shows the page for a missing poll.
 */
final readonly class PollAction
{
    public function __construct(private Renderer $renderer) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($request, $response, 'pages/poll_missing.html.twig', status: 404);
    }
}
