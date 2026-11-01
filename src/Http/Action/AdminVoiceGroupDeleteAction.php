<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\SettingsPage;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Slim\Exception\HttpNotFoundException;

/** Removes a voice group that no member and no poll uses. */
final readonly class AdminVoiceGroupDeleteAction
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
        if (!$this->voiceGroups->exists($args['key'])) {
            throw new HttpNotFoundException($request);
        }
        $toastKey = $this->voiceGroups->removeUnused($args['key']) ? 'toast.group.removed' : 'toast.group.in_use';

        return $this->page->answer($request, $response, SettingsPage::VOICE_GROUPS, $toastKey);
    }
}
