<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Renderer;

/**
 * The admin area's poll list. It shows the page head until polls can be created.
 */
final readonly class AdminPollsAction
{
    public function __construct(private Renderer $renderer) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($request, $response, 'pages/admin/polls.html.twig');
    }
}
