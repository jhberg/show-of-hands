<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\PollEditing;
use ShowOfHands\Http\AdminPollsPage;
use ShowOfHands\Http\PollForm;
use ShowOfHands\Http\Redirect;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;

/**
 * The form for a new poll (GET /admin/polls/new) or for editing one (GET /admin/polls/{id}/edit).
 * An open poll offers only its note and date. A closed one is not editable, so its link leads
 * back to the detail.
 */
final readonly class AdminPollFormAction
{
    public function __construct(
        private AdminPollsPage $page,
        private PollRepository $polls,
        private VoiceGroupRepository $voiceGroups,
    ) {}

    /**
     * @param array{id?: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!isset($args['id'])) {
            return $this->page->form($request, $response, PollForm::blank($this->voiceGroups->all()[0]->key ?? ''));
        }
        $poll = $this->polls->find((int) $args['id']);
        if ($poll === null) {
            return $this->page->missing($request, $response);
        }
        if ($poll->editing() === PollEditing::Nothing) {
            return Redirect::to($response, $request, AdminPollsPage::path($poll->id));
        }

        return $this->page->form($request, $response, PollForm::of($poll));
    }
}
