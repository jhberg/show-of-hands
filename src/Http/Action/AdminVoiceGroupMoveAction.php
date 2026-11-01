<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\SettingsPage;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/** Moves a voice group one place up or down. The order sorts every member list. */
final readonly class AdminVoiceGroupMoveAction
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
        $body = $request->getParsedBody();
        $step = match (is_array($body) ? ($body['direction'] ?? null) : null) {
            'up' => -1,
            'down' => 1,
            default => throw new HttpBadRequestException($request),
        };
        $this->voiceGroups->move($args['key'], $step);

        return $this->page->answer($request, $response, SettingsPage::VOICE_GROUPS, null);
    }
}
