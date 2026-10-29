<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\Renderer;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;

/**
 * The member's start page: open polls and closed ones with their result.
 */
final readonly class HomeAction
{
    public function __construct(
        private Renderer $renderer,
        private SettingsRepository $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $member = MemberMiddleware::member($request);
        assert($member !== null);

        return $this->renderer->render($request, $response, 'pages/home.html.twig', [
            'first_name' => $member->firstName(),
            'open_polls' => [],
            'closed_polls' => [],
            'retention_days' => $this->settings->retentionDays(),
        ]);
    }
}
