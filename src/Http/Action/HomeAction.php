<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\Renderer;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;

/**
 * The member's start page: open polls and closed ones with their result.
 */
final readonly class HomeAction
{
    public function __construct(
        private Renderer $renderer,
        private SettingsRepository $settings,
        private PollRepository $polls,
        private BallotRepository $ballots,
        private DelegationRepository $delegations,
        private MemberRepository $members,
        private VoiceGroupRepository $voiceGroups,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $member = MemberMiddleware::member($request);
        assert($member !== null);

        $open = $this->polls->open();
        $openIds = array_map(static fn(Poll $poll): int => $poll->id, $open);
        $voted = array_flip($this->ballots->pollsVotedIn($member->id, $openIds));
        $recipients = $this->delegations->recipientsOf($member->id, $openIds);
        $firstNames = [];
        foreach (array_unique($recipients) as $recipientId) {
            $firstNames[$recipientId] = $this->members->find($recipientId)?->firstName();
        }
        $groups = $this->voiceGroups->all();

        return $this->renderer->render($request, $response, 'pages/home.html.twig', [
            'first_name' => $member->firstName(),
            'open_polls' => array_map(static fn(Poll $poll): array => [
                'poll' => $poll,
                'group_label' => VoiceGroup::labelOf($groups, $poll->voiceGroupKey),
                'voted' => isset($voted[$poll->id]),
                'delegated_to' => $firstNames[$recipients[$poll->id] ?? 0] ?? null,
            ], $open),
            'closed_polls' => [],
            'retention_days' => $this->settings->retentionDays(),
        ]);
    }
}
