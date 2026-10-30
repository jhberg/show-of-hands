<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\Choice;
use ShowOfHands\Domain\Delegations;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\Turnout;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Persistence\BallotRepository;
use ShowOfHands\Infrastructure\Persistence\DelegationRepository;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\RollRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Symfony\Component\Translation\Translator;

/**
 * The poll page for every role: head, then the ballot with the vote delegation while the poll is
 * open or the result once it is closed, the turnout, and the board's close. The full page and
 * the htmx fragment render the same <main>, so its sections never disagree after a tap.
 */
final readonly class PollPage
{
    public function __construct(
        private Renderer $renderer,
        private Flash $flash,
        private Translator $translator,
        private PollRepository $polls,
        private BallotRepository $ballots,
        private DelegationRepository $delegations,
        private MemberRepository $members,
        private VoiceGroupRepository $voiceGroups,
        private SettingsRepository $settings,
        private RollRepository $roll,
        private Config $config,
    ) {}

    /**
     * The whole page, with the toast a redirect left in the session. ?delegate=1 opens the
     * delegation panel, ?confirm=close the board's confirmation.
     */
    public function page(ServerRequestInterface $request, ResponseInterface $response, Poll $poll, Member $member): ResponseInterface
    {
        $data = $this->data($poll, $member, self::delegationOpen($request), self::confirmClose($request));
        // The flash holds only the message key. The choice it names comes from the member's own ballot.
        $data['toast'] = $this->flash->take($this->choiceParams($data['choice']), self::path($poll));

        return $this->renderer->render($request, $response, 'pages/poll.html.twig', $data);
    }

    /**
     * Only <main>, for htmx to swap. The toast travels in the HX-Trigger header, because the
     * toast region lies outside <main>.
     *
     * @param string|null $toastKey a message that may name the member's choice as {choice}
     * @param array<string, string> $toastParams the message's other placeholders
     */
    public function fragment(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Poll $poll,
        Member $member,
        ?string $toastKey = null,
        array $toastParams = [],
        bool $delegationOpen = false,
        bool $confirmClose = false,
    ): ResponseInterface {
        $data = $this->data($poll, $member, $delegationOpen, $confirmClose);
        if ($toastKey !== null) {
            $toast = $this->translator->trans($toastKey, $toastParams + $this->choiceParams($data['choice']));
            $response = $response->withHeader('HX-Trigger', json_encode(['toast' => $toast], JSON_THROW_ON_ERROR));
        }

        // A null toast keeps the Renderer from taking a pending flash, which this fragment cannot show.
        return $this->renderer->render($request, $response, 'partials/poll/main.html.twig', $data + ['toast' => null]);
    }

    /**
     * The answer to a POST on the poll page: the fresh <main> for htmx, otherwise a redirect
     * that leaves the toast for this poll's page only.
     *
     * @param string $toastKey a message that may name the member's choice as {choice}
     * @param array<string, string> $toastParams the message's other placeholders, never a choice
     * @param bool $delegationOpen whether the delegation panel stays open
     */
    public function answer(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Poll $poll,
        Member $member,
        string $toastKey,
        array $toastParams = [],
        bool $delegationOpen = false,
    ): ResponseInterface {
        if ($request->getHeaderLine('HX-Request') === 'true') {
            // Re-read, because the poll may have closed in the meantime.
            $poll = $this->polls->findPublished($poll->id);
            if ($poll === null) {
                return $this->missing($request, $response);
            }

            return $this->fragment($request, $response, $poll, $member, $toastKey, $toastParams, $delegationOpen);
        }
        $this->flash->toast($toastKey, $toastParams, page: self::path($poll));

        return Redirect::to($response, $request, self::path($poll) . ($delegationOpen ? '?delegate=1' : ''));
    }

    /**
     * The page for a poll that does not exist or is still a draft. Both look the same, so a
     * guessed id reveals nothing.
     */
    public function missing(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($request, $response, 'pages/poll_missing.html.twig', status: 404);
    }

    /** Whether the request asks for the open delegation panel. */
    public static function delegationOpen(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()['delegate'] ?? null) === '1';
    }

    /** Whether the request asks for the board's confirmation before closing. */
    public static function confirmClose(ServerRequestInterface $request): bool
    {
        return ($request->getQueryParams()['confirm'] ?? null) === 'close';
    }

    /**
     * @return array{poll: Poll, group_label: string, choice: Choice|null, turnout: Turnout, missing_by_group: list<array{group: VoiceGroup|null, members: non-empty-list<Member>}>, show_names: bool, recipient: Member|null, recipient_voted: bool|null, senders: list<string>, delegation: array{open: bool, max_received: int, targets: list<array{id: int, name: string, group_label: string|null, voted: bool}>}|null, board: array{confirm: bool, pending: int}|null, deletion_day: string|null}
     */
    private function data(Poll $poll, Member $member, bool $delegationOpen, bool $confirmClose): array
    {
        $groups = $this->voiceGroups->all();
        $locale = $this->translator->getLocale();
        $showNames = $member->isBoard || $member->isAdmin || $this->settings->showOpenNames();
        if ($poll->isOpen()) {
            $members = $this->members->all($locale);
            $participantIds = $this->ballots->participantIds($poll->id);
            $delegations = $this->delegations->forPoll($poll->id);
            $turnout = Turnout::of($members, $participantIds, $delegations);
        } else {
            // A closed poll shows the turnout as it stood at the close. It has no delegation
            // block, so the live data it would need stays empty.
            [$members, $participantIds, $delegations] = [[], [], new Delegations()];
            $turnout = $this->roll->turnout($poll->id, $locale);
        }
        $recipient = null;
        $recipientVoted = false;
        $senders = [];
        $pendingDelegations = 0;
        foreach ($turnout->delegations as $entry) {
            if ($entry['from']->id === $member->id) {
                $recipient = $entry['to'];
                $recipientVoted = $entry['to_voted'];
            } elseif ($entry['to']->id === $member->id) {
                $senders[] = $entry['from']->name;
            }
            if (!$entry['to_voted']) {
                ++$pendingDelegations;
            }
        }
        $delegation = null;
        if ($poll->isOpen() && $recipient === null && $senders === [] && $this->settings->delegationEnabled()) {
            $delegation = $this->delegationBlock($groups, $members, $member, $delegations, array_flip($participantIds), $showNames, $delegationOpen);
        }

        return [
            'poll' => $poll,
            'group_label' => VoiceGroup::labelOf($groups, $poll->voiceGroupKey),
            // Read even when the poll has just closed, so a toast for a ballot cast a moment
            // before the close still names the choice. It is only ever the member's own row.
            'choice' => $this->ballots->choice($poll->id, $member->id),
            'turnout' => $turnout,
            'missing_by_group' => $turnout->missingByGroup($groups),
            'show_names' => $showNames,
            'recipient' => $recipient,
            // Whether the recipient voted reveals turnout, so it follows the names setting.
            'recipient_voted' => $showNames && $recipient !== null ? $recipientVoted : null,
            'senders' => $senders,
            'delegation' => $delegation,
            'board' => $poll->isOpen() && $member->isBoard ? ['confirm' => $confirmClose, 'pending' => $pendingDelegations] : null,
            'deletion_day' => $this->deletionDay($poll),
        ];
    }

    /** The day retention deletes the closed poll, as Y-m-d in the choir's time zone. */
    private function deletionDay(Poll $poll): ?string
    {
        if ($poll->closedAt === null) {
            return null;
        }

        return (new \DateTimeImmutable($poll->closedAt))
            ->modify('+' . $this->settings->retentionDays() . ' days')
            ->setTimezone(new \DateTimeZone($this->config->timezone))
            ->format('Y-m-d');
    }

    /**
     * The delegation block: closed, or open with the members the vote can go to.
     *
     * @param list<VoiceGroup> $groups
     * @param list<Member> $members
     * @param array<int, int> $voted member ids with a ballot, as keys
     *
     * @return array{open: bool, max_received: int, targets: list<array{id: int, name: string, group_label: string|null, voted: bool}>}
     */
    private function delegationBlock(array $groups, array $members, Member $member, Delegations $delegations, array $voted, bool $showNames, bool $open): array
    {
        $maxReceived = $this->settings->maxReceived();

        return [
            'open' => $open,
            'max_received' => $maxReceived,
            'targets' => $open ? array_map(static fn(Member $target): array => [
                'id' => $target->id,
                'name' => $target->name,
                'group_label' => $target->voiceGroupKey === null ? null : VoiceGroup::labelOf($groups, $target->voiceGroupKey),
                'voted' => $showNames && isset($voted[$target->id]),
            ], $delegations->targets($members, $member->id, $maxReceived)) : [],
        ];
    }

    /** The poll page's path, which also scopes its toasts to this poll. */
    public static function path(Poll $poll): string
    {
        return '/polls/' . $poll->id;
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
