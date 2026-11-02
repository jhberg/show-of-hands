<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Domain\VoiceGroupError;
use ShowOfHands\Http\SettingsPage;
use ShowOfHands\Http\VoiceGroupForm;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;

/**
 * Adds a voice group at the end of the order. A refused add shows the form again with status
 * 422, the values as entered, and the message at its field.
 */
final readonly class AdminVoiceGroupAddAction
{
    public function __construct(
        private SettingsPage $page,
        private VoiceGroupRepository $voiceGroups,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $text = static fn(string $key): string => is_string($body[$key] ?? null) ? $body[$key] : '';
        $form = new VoiceGroupForm($text('key'), $text('label'));
        $group = VoiceGroup::parse($form->key, $form->label);
        if ($group instanceof VoiceGroup && !$this->voiceGroups->add($group)) {
            $group = VoiceGroupError::KeyTaken;
        }
        if ($group instanceof VoiceGroupError) {
            return $this->page->show($request, $response, $form->refused($group), 422);
        }

        return $this->page->answer($request, $response, SettingsPage::VOICE_GROUPS, 'toast.group.added', ['label' => $group->label]);
    }
}
