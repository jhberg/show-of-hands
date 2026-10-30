<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\Turnout;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Symfony\Component\Translation\Translator;

/**
 * The poll page: head, ballot, and turnout, for every role. The full page and the htmx
 * fragment render the same <main>, so ballot and turnout never disagree after a tap.
 */
final readonly class PollPage
{
    public function __construct(
        private Renderer $renderer,
        private Flash $flash,
        private Translator $translator,
        private BallotRepository $ballots,
        private MemberRepository $members,
        private VoiceGroupRepository $voiceGroups,
        private SettingsRepository $settings,
    ) {}

    /**
     * The whole page, with the toast a redirect left in the session.
     */
    public function page(ServerRequestInterface $request, ResponseInterface $response, Poll $poll, Member $member): ResponseInterface
    {
        $data = $this->data($poll, $member);
        // The flash holds only the message key. The choice it names comes from the member's own ballot.
        $data['toast'] = $this->flash->take($this->choiceParams($data['choice']));

        return $this->renderer->render($request, $response, 'pages/poll.html.twig', $data);
    }

    /**
     * Only <main>, for htmx to swap. The toast travels in the HX-Trigger header, because the
     * toast region lies outside <main>.
     *
     * @param string|null $toastKey a message that may name the member's choice as {choice}
     */
    public function fragment(ServerRequestInterface $request, ResponseInterface $response, Poll $poll, Member $member, ?string $toastKey = null): ResponseInterface
    {
        $data = $this->data($poll, $member);
        if ($toastKey !== null) {
            $toast = $this->translator->trans($toastKey, $this->choiceParams($data['choice']));
            $response = $response->withHeader('HX-Trigger', json_encode(['toast' => $toast], JSON_THROW_ON_ERROR));
        }

        // A null toast keeps the Renderer from taking a pending flash, which this fragment cannot show.
        return $this->renderer->render($request, $response, 'partials/poll/main.html.twig', $data + ['toast' => null]);
    }

    /**
     * The page for a poll that does not exist or is still a draft. Both look the same, so a
     * guessed id reveals nothing.
     */
    public function missing(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($request, $response, 'pages/poll_missing.html.twig', status: 404);
    }

    /**
     * @return array{poll: Poll, group_label: string, choice: Choice|null, turnout: Turnout, missing_by_group: list<array{group: VoiceGroup|null, members: non-empty-list<Member>}>, show_names: bool}
     */
    private function data(Poll $poll, Member $member): array
    {
        $groups = $this->voiceGroups->all();
        $turnout = Turnout::of($this->members->all($this->translator->getLocale()), $this->ballots->participantIds($poll->id));

        return [
            'poll' => $poll,
            'group_label' => VoiceGroup::labelOf($groups, $poll->voiceGroupKey),
            // Read even when the poll has just closed, so a toast for a ballot cast a moment
            // before the close still names the choice. It is only ever the member's own row.
            'choice' => $this->ballots->choice($poll->id, $member->id),
            'turnout' => $turnout,
            'missing_by_group' => $turnout->missingByGroup($groups),
            'show_names' => $member->isBoard || $member->isAdmin || $this->settings->showOpenNames(),
        ];
    }

    /**
     * The {choice} placeholder of the ballot toasts, empty before the member voted.
     *
     * @return array<string, string>
     */
    private function choiceParams(?Choice $choice): array
    {
        return match ($choice) {
            null => [],
            Choice::Yes => ['choice' => $this->translator->trans('ballot.yes')],
            Choice::No => ['choice' => $this->translator->trans('ballot.no')],
        };
    }
}
