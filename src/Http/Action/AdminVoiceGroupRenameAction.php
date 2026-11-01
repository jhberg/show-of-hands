<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\SettingsPage;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Slim\Exception\HttpNotFoundException;

/**
 * Renames a voice group in place. An empty name keeps the old one, and an unchanged name
 * answers without a toast, since with htmx both Enter and leaving the field send it.
 */
final readonly class AdminVoiceGroupRenameAction
{
    public function __construct(
        private SettingsPage $page,
        private VoiceGroupRepository $voiceGroups,
    ) {}

    /**
     * @param array{key: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $group = $this->voiceGroups->find($args['key']);
        if ($group === null) {
            throw new HttpNotFoundException($request);
        }
        $body = $request->getParsedBody();
        $label = is_array($body) && is_string($body['label'] ?? null) ? trim($body['label']) : '';
        if ($label === '' || $label === $group->label) {
            return $this->page->answer($request, $response, SettingsPage::VOICE_GROUPS, null);
        }
        $this->voiceGroups->rename($group->key, $label);

        return $this->page->answer($request, $response, SettingsPage::VOICE_GROUPS, 'toast.group.renamed', ['label' => $label]);
    }
}
