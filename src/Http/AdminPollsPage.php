<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\Poll;
use ShowOfHands\Domain\Turnout;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Clock\Clock;
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
 * The admin area's polls: the list, the form that creates or edits one, and the detail with its
 * status panel, result, and turnout. Turnout names everyone who is missing, whatever the names
 * setting says. No page here ever shows a count of choices while a poll is open.
 *
 * Confirmations live in ?confirm=delete and ?confirm=reopen, so they work without JavaScript.
 * With htmx, the detail's links and buttons swap #poll-detail, and a refused save swaps the form.
 */
final readonly class AdminPollsPage
{
    public const PATH = '/admin/polls';

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
        private Clock $clock,
    ) {}

    /** The poll's detail page. */
    public static function path(int $pollId): string
    {
        return self::PATH . '/' . $pollId;
    }

    /**
     * Every poll: open ones first, then drafts, then closed ones, each by date, the latest first.
     */
    public function list(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $groups = $this->voiceGroups->all();
        $retentionDays = $this->settings->retentionDays();
        $zone = new \DateTimeZone($this->config->timezone);
        $members = null;
        $rows = [];
        foreach ($this->polls->all() as $poll) {
            $row = [
                'poll' => $poll,
                'group_label' => VoiceGroup::labelOf($groups, $poll->voiceGroupKey),
                'cast' => null,
                'total' => null,
                'deletion_day' => $poll->deletionDay($retentionDays, $zone),
            ];
            if ($poll->isOpen()) {
                // Only the counts matter here, so the order does not.
                $members ??= $this->members->all('root');
                $turnout = $this->liveTurnout($poll, $members);
                $row['cast'] = $turnout->castWeight;
                $row['total'] = $turnout->eligible;
            } elseif ($poll->tally !== null) {
                $row['cast'] = $poll->tally->cast();
                $row['total'] = $poll->tally->eligible;
            }
            $rows[] = $row;
        }

        return $this->renderer->render($request, $response, 'pages/admin/polls.html.twig', ['rows' => $rows]);
    }

    /**
     * The detail page or, for htmx, #poll-detail.
     *
     * @param string|null $confirm 'delete' for a draft, 'reopen' for a poll closed today, as ?confirm= names it
     */
    public function detail(ServerRequestInterface $request, ResponseInterface $response, Poll $poll, ?string $confirm, ?string $toast = null): ResponseInterface
    {
        return $this->renderer->panel(
            $request,
            $response,
            'pages/admin/poll.html.twig',
            'partials/admin/poll_detail.html.twig',
            $this->detailData($poll, $confirm),
            $toast,
        );
    }

    /**
     * The confirmation ?confirm= names, delete or reopen. The detail shows it only where the poll's status allows it.
     */
    public static function confirm(ServerRequestInterface $request): ?string
    {
        $confirm = $request->getQueryParams()['confirm'] ?? null;

        return in_array($confirm, ['delete', 'reopen'], true) ? $confirm : null;
    }

    /**
     * The page for a poll that does not exist, perhaps because retention deleted it.
     */
    public function missing(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->renderer->render($request, $response, 'pages/admin/poll_missing.html.twig', [
            'retention_days' => $this->settings->retentionDays(),
        ], 404);
    }

    /**
     * The form page or, after a refused save with htmx, the form alone, with the message as the toast.
     */
    public function form(ServerRequestInterface $request, ResponseInterface $response, PollForm $form, int $status = 200): ResponseInterface
    {
        $toast = $form->errorKey === null ? null : $this->translator->trans($form->errorKey);

        return $this->renderer->panel($request, $response, 'pages/admin/poll_form.html.twig', 'partials/admin/poll_form.html.twig', [
            'form' => $form,
            'groups' => $this->voiceGroups->all(),
        ], $toast, $status);
    }

    /**
     * The answer to a status change on the detail page: the fresh #poll-detail for htmx, otherwise
     * a redirect to the detail that leaves the toast in the session.
     */
    public function answer(ServerRequestInterface $request, ResponseInterface $response, int $pollId, string $toastKey): ResponseInterface
    {
        if (!Htmx::isRequest($request)) {
            return $this->redirect($request, $response, self::path($pollId), $toastKey);
        }
        $poll = $this->polls->find($pollId);
        if ($poll === null) {
            return $this->redirect($request, $response, self::PATH, $toastKey);
        }

        // The address bar follows, since the detail no longer shows the confirmation it named.
        return $this->detail($request, $response->withHeader('HX-Replace-Url', self::path($pollId)), $poll, null, $this->translator->trans($toastKey));
    }

    /**
     * A redirect to another page, which shows the toast. htmx follows it with a full page load.
     */
    public function redirect(ServerRequestInterface $request, ResponseInterface $response, string $location, string $toastKey): ResponseInterface
    {
        $this->flash->toast($toastKey);

        return Redirect::to($response, $request, $location);
    }

    /**
     * @return array<string, mixed>
     */
    private function detailData(Poll $poll, ?string $confirm): array
    {
        $groups = $this->voiceGroups->all();
        $zone = new \DateTimeZone($this->config->timezone);
        $data = [
            'poll' => $poll,
            'group_label' => VoiceGroup::labelOf($groups, $poll->voiceGroupKey),
            'confirm' => $confirm,
            'share_url' => $this->config->appUrl . PollPage::path($poll),
            'deletion_day' => $poll->deletionDay($this->settings->retentionDays(), $zone),
            'can_reopen' => $poll->reopenRefusal($this->clock->now(), $zone) === null,
            // The admin area names everyone who is missing, as the poll page does for board and admins.
            'show_names' => true,
            'turnout' => null,
        ];
        if ($poll->isDraft()) {
            return $data;
        }
        $locale = $this->translator->getLocale();
        if ($poll->isOpen()) {
            $members = $this->members->all($locale);
            $turnout = $this->liveTurnout($poll, $members);
            $data['board_names'] = array_values(array_map(
                static fn(Member $member): string => $member->firstName(),
                array_filter($members, static fn(Member $member): bool => $member->isBoard),
            ));
        } else {
            $turnout = $this->roll->turnout($poll->id, $locale);
        }

        $data['turnout'] = $turnout;
        $data['missing_by_group'] = $turnout->missingByGroup($groups);

        return $data;
    }

    /**
     * Who has voted in an open poll. It reads turnout and delegations, never a ballot.
     *
     * @param list<Member> $members every member, in voice group order, then by name
     */
    private function liveTurnout(Poll $poll, array $members): Turnout
    {
        return Turnout::of($members, $this->ballots->participantIds($poll->id), $this->delegations->forPoll($poll->id));
    }
}
