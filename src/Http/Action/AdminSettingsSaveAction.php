<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\Settings;
use ShowOfHands\Http\SettingsPage;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use Slim\Exception\HttpBadRequestException;

/**
 * Saves the settings form. The form offers only allowed values, so anything else is a forged
 * request and gets 400. Switching delegation off or lowering the limit keeps existing delegations.
 */
final readonly class AdminSettingsSaveAction
{
    public function __construct(
        private SettingsPage $page,
        private SettingsRepository $settings,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        // 0 is never a choice, so Settings refuses a missing or non-numeric value.
        $number = static fn(string $key): int => is_string($body[$key] ?? null) && ctype_digit($body[$key]) ? (int) $body[$key] : 0;
        try {
            $settings = new Settings(
                ($body['delegation'] ?? null) === '1',
                $number('max_received'),
                ($body['show_open_names'] ?? null) === '1',
                $number('retention_days'),
            );
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }
        $this->settings->save($settings);

        return $this->page->answer($request, $response, SettingsPage::SETTINGS, 'toast.settings.saved');
    }
}
