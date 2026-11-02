<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\PollDetails;
use ShowOfHands\Domain\PollEditing;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Http\AdminPollsPage;
use ShowOfHands\Http\PollForm;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Slim\Exception\HttpBadRequestException;

/**
 * Creates a draft (POST /admin/polls) or saves a poll (POST /admin/polls/{id}). A refused save
 * shows the form again with status 422, the values as entered, and the message at its field.
 * An open poll saves only its note and date, and a closed one nothing. The form offers only
 * kinds, groups, and dates that exist, so anything else is a forged request and gets 400.
 */
final readonly class AdminPollSaveAction
{
    public function __construct(
        private AdminPollsPage $page,
        private PollRepository $polls,
        private VoiceGroupRepository $voiceGroups,
        private Clock $clock,
    ) {}

    /**
     * @param array{id?: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $poll = null;
        if (isset($args['id'])) {
            $poll = $this->polls->find((int) $args['id']);
            if ($poll === null) {
                return $this->page->missing($request, $response);
            }
            if ($poll->editing() === PollEditing::Nothing) {
                return $this->page->redirect($request, $response, AdminPollsPage::path($poll->id), 'toast.poll.stale');
            }
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $kind = PollKind::tryFrom(is_string($body['kind'] ?? null) ? $body['kind'] : '');
        $form = PollForm::fromBody($body, $kind ?? PollKind::Admission, $poll);
        if ($form->editsAll() && ($kind === null || !$this->voiceGroups->exists($form->group))) {
            throw new HttpBadRequestException($request);
        }
        try {
            $details = PollDetails::parse($form->kind, $form->subjectName, $form->question, $form->group, $form->date, $form->note);
        } catch (\InvalidArgumentException $e) {
            throw new HttpBadRequestException($request, $e->getMessage(), $e);
        }
        if (!$details instanceof PollDetails) {
            return $this->page->form($request, $response, $form->refused($details), 422);
        }

        if ($poll === null) {
            $id = $this->polls->create($details, $this->clock->now());

            return $this->page->redirect($request, $response, AdminPollsPage::path($id), 'toast.poll.created');
        }
        $saved = $this->polls->update($poll->id, $details);
        if ($saved === null) {
            return $this->page->missing($request, $response);
        }

        // Only the full form sends the name. A draft published or a poll closed since the form
        // loaded kept some of what the form wanted to change.
        $wanted = array_key_exists('subject_name', $body) ? PollEditing::All : PollEditing::NoteAndDate;

        return $this->page->redirect($request, $response, AdminPollsPage::path($poll->id), $saved === $wanted ? 'toast.poll.saved' : 'toast.poll.stale');
    }
}
