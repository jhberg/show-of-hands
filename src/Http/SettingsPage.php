<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\MajorityBase;
use ShowOfHands\Domain\MajorityShare;
use ShowOfHands\Domain\Settings;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\SettingsRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Symfony\Component\Translation\Translator;

/**
 * The admin area's settings page: the settings form, the voice groups, and the read-only
 * sign-in values from config.php. The full page and the htmx responses render the same
 * partials; htmx swaps only the panel the form belongs to.
 */
final readonly class SettingsPage
{
    private const PATH = '/admin/settings';

    public const SETTINGS = 'settings';

    public const VOICE_GROUPS = 'voice-groups';

    private const PARTIALS = [
        self::SETTINGS => 'partials/admin/settings_form.html.twig',
        self::VOICE_GROUPS => 'partials/admin/voice_groups.html.twig',
    ];

    public function __construct(
        private Renderer $renderer,
        private Flash $flash,
        private Translator $translator,
        private SettingsRepository $settings,
        private MemberRepository $members,
        private VoiceGroupRepository $voiceGroups,
        private Config $config,
    ) {}

    /**
     * The page, or after a refused add (htmx) the voice group panel, with the message as the toast.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response, ?VoiceGroupForm $form = null, int $status = 200): ResponseInterface
    {
        $toast = $form?->errorKey === null ? null : $this->translator->trans($form->errorKey);

        return $this->respond($request, $response, self::VOICE_GROUPS, $form ?? new VoiceGroupForm(), $toast, $status);
    }

    /**
     * The answer to a POST: the fresh panel for htmx, otherwise a redirect to that panel that
     * leaves the toast in the session.
     *
     * @param string $panel SETTINGS or VOICE_GROUPS
     * @param string|null $toastKey null for an answer without a toast
     * @param array<string, string> $toastParams
     */
    public function answer(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $panel,
        ?string $toastKey,
        array $toastParams = [],
    ): ResponseInterface {
        if (!Htmx::isRequest($request)) {
            if ($toastKey !== null) {
                $this->flash->toast($toastKey, $toastParams);
            }

            return Redirect::to($response, $request, self::PATH . '#' . $panel);
        }

        $toast = $toastKey === null ? null : $this->translator->trans($toastKey, $toastParams);

        return $this->respond($request, $response, $panel, new VoiceGroupForm(), $toast);
    }

    private function respond(ServerRequestInterface $request, ResponseInterface $response, string $panel, VoiceGroupForm $form, ?string $toast, int $status = 200): ResponseInterface
    {
        return $this->renderer->panel($request, $response, 'pages/admin/settings.html.twig', self::PARTIALS[$panel], $this->data($form), $toast, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function data(VoiceGroupForm $form): array
    {
        $eligible = $this->members->count();
        $settings = $this->settings->all();

        return [
            'settings' => $settings,
            'majority_shares' => MajorityShare::cases(),
            'majority_bases' => MajorityBase::cases(),
            'max_received_choices' => Settings::MAX_RECEIVED_CHOICES,
            'retention_days_choices' => Settings::RETENTION_DAYS_CHOICES,
            'eligible' => $eligible,
            // The example counts as if every member voted, which is the same for either base.
            'needed' => $settings->majority->share->needed($eligible),
            'groups' => $this->voiceGroups->all(),
            'groups_in_use' => $this->voiceGroups->keysInUse(),
            'group_form' => $form,
            'workspace_domain' => $this->config->workspaceDomain,
            'client_id' => $this->config->googleClientId,
        ];
    }
}
