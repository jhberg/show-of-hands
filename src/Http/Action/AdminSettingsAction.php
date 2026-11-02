<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\SettingsPage;

/** The settings page: settings, voice groups, and the sign-in values from config.php. */
final readonly class AdminSettingsAction
{
    public function __construct(private SettingsPage $page) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->page->show($request, $response);
    }
}
