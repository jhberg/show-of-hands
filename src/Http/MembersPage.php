<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\Member;
use ShowOfHands\Domain\VoiceGroup;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Symfony\Component\Translation\Translator;

/**
 * The admin area's member list with its inline add, edit, and delete. The full page and the
 * htmx responses render the same partials: the whole panel (#members), or only the list
 * (#member-list) while the admin types a search.
 */
final readonly class MembersPage
{
    public const PATH = '/admin/members';

    private const LIST_TARGET = 'member-list';

    public function __construct(
        private Renderer $renderer,
        private Flash $flash,
        private Translator $translator,
        private MemberRepository $members,
        private VoiceGroupRepository $voiceGroups,
        private Config $config,
    ) {}

    /**
     * The page or, for htmx, the part it asked for. A refused save shows its message as the toast.
     *
     * @param string $query the search, matched against names and addresses
     * @param MemberForm|null $form the open add or edit form
     * @param int|null $deleting the member whose removal awaits confirmation
     */
    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Member $actor,
        string $query,
        ?MemberForm $form = null,
        ?int $deleting = null,
        int $status = 200,
    ): ResponseInterface {
        $toast = $form?->errorKey === null ? null : $this->translator->trans($form->errorKey, $form->errorParams);

        return $this->respond($request, $response, $this->data($actor, $query, $form, $deleting), $toast, $status);
    }

    /**
     * The answer to a successful POST: the fresh panel for htmx, otherwise a redirect that leaves
     * the toast in the session. Both keep the search.
     *
     * @param array<string, string> $toastParams
     */
    public function answer(
        ServerRequestInterface $request,
        ResponseInterface $response,
        Member $actor,
        string $query,
        string $toastKey,
        array $toastParams = [],
    ): ResponseInterface {
        if (!Htmx::isRequest($request)) {
            $this->flash->toast($toastKey, $toastParams);

            return Redirect::to($response, $request, self::listUrl($query));
        }

        // The address bar follows, since the panel no longer shows the form or the confirmation it named.
        $response = $response->withHeader('HX-Replace-Url', self::listUrl($query));

        return $this->respond($request, $response, $this->data($actor, $query, null, null), $this->translator->trans($toastKey, $toastParams));
    }

    /** The search a GET request or a form's hidden field carries. */
    public static function query(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /**
     * The open form for ?add=1 or ?edit={id}, or null.
     */
    public function formFor(ServerRequestInterface $request): ?MemberForm
    {
        $params = $request->getQueryParams();
        if (($params['add'] ?? null) === '1') {
            return MemberForm::blank($this->voiceGroups->all()[0]->key ?? null);
        }
        $edit = self::id($params['edit'] ?? null);
        $member = $edit === null ? null : $this->members->find($edit);

        return $member === null ? null : MemberForm::of($member, $this->members->emailsOf($member->id));
    }

    /** The member whose removal ?delete={id} asks to confirm. */
    public static function deleting(ServerRequestInterface $request): ?int
    {
        return self::id($request->getQueryParams()['delete'] ?? null);
    }

    private static function id(mixed $value): ?int
    {
        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }

    /** The list, keeping the search. */
    private static function listUrl(string $query): string
    {
        return self::PATH . ($query === '' ? '' : '?' . http_build_query(['q' => $query]));
    }

    /**
     * The page, or the part htmx asked for: the list while the admin types a search, else the panel.
     *
     * @param array<string, mixed> $data
     */
    private function respond(ServerRequestInterface $request, ResponseInterface $response, array $data, ?string $toast, int $status = 200): ResponseInterface
    {
        $fragment = $request->getHeaderLine('HX-Target') === self::LIST_TARGET ? 'partials/admin/member_list.html.twig' : 'partials/admin/members.html.twig';

        return $this->renderer->panel($request, $response, 'pages/admin/members.html.twig', $fragment, $data, $toast, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Member $actor, string $query, ?MemberForm $form, ?int $deleting): array
    {
        $groups = $this->voiceGroups->all();
        $all = $this->members->allWithEmails($this->translator->getLocale());
        $needle = mb_strtolower($query);
        $matches = static fn(string $text): bool => str_contains(mb_strtolower($text), $needle);
        $rows = [];
        foreach ($all as $entry) {
            if ($needle !== '' && array_filter([$entry['member']->name, ...$entry['emails']], $matches) === []) {
                continue;
            }
            $key = $entry['member']->voiceGroupKey;
            $rows[] = $entry + ['group_label' => $key === null ? null : VoiceGroup::labelOf($groups, $key)];
        }
        $members = array_column($all, 'member');

        return [
            'query' => $query,
            'list_url' => self::listUrl($query),
            'rows' => $rows,
            'counts' => [
                'eligible' => count($members),
                'board' => count(array_filter($members, static fn(Member $member): bool => $member->isBoard)),
                'admin' => count(array_filter($members, static fn(Member $member): bool => $member->isAdmin)),
            ],
            'form' => $form,
            'deleting' => $deleting === $actor->id ? null : $deleting,
            'groups' => $groups,
            'email_domain' => $this->config->workspaceDomain,
        ];
    }
}
