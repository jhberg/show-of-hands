<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Domain\MemberDetails;
use ShowOfHands\Http\MemberForm;
use ShowOfHands\Http\MembersPage;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\Session;
use ShowOfHands\Infrastructure\Persistence\DuplicateEmailException;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\VoiceGroupRepository;
use Slim\Exception\HttpNotFoundException;

/**
 * Adds a member (POST /admin/members) or saves one (POST /admin/members/{id}). A refused save
 * shows the form again with status 422, the values as entered, and the message at its field.
 * Admins keep their own admin role whatever the form says, and the address they signed in with. The list afterwards shows every member,
 * so the new or edited one is in it.
 */
final readonly class AdminMemberSaveAction
{
    public function __construct(
        private MembersPage $page,
        private MemberRepository $members,
        private VoiceGroupRepository $voiceGroups,
        private Session $session,
    ) {}

    /**
     * @param array{id?: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = MemberMiddleware::member($request);
        assert($actor !== null);
        $id = isset($args['id']) ? (int) $args['id'] : null;
        if ($id !== null && $this->members->find($id) === null) {
            throw new HttpNotFoundException($request);
        }
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $form = MemberForm::fromBody($body, $id);
        $details = MemberDetails::parse($form->name, $form->email, $form->email2);
        if (!$details instanceof MemberDetails) {
            return $this->page->show($request, $response, $actor, '', $form->refused($details), status: 422);
        }
        // The session holds the address the admin signed in with. Without it they would be signed
        // out, and a mistyped replacement would lock them out for good.
        if ($id === $actor->id && !in_array($this->session->get(MemberMiddleware::EMAIL_KEY), $details->emails, true)) {
            return $this->page->show($request, $response, $actor, '', $form->withoutOwnAddress(), status: 422);
        }
        // A group removed in the meantime leaves the member without one.
        $group = $form->group !== null && $this->voiceGroups->exists($form->group) ? $form->group : null;
        $admin = $form->admin;
        $toastKey = $id === null ? 'toast.member.added' : 'toast.member.saved';
        if ($id === $actor->id && !$admin) {
            $admin = true;
            $toastKey = 'toast.member.own_admin';
        }

        try {
            if ($id === null) {
                $this->members->add($details->name, $details->emails, $group, $form->board, $admin);
            } elseif (!$this->members->update($id, $details->name, $details->emails, $group, $form->board, $admin)) {
                throw new HttpNotFoundException($request);
            }
        } catch (DuplicateEmailException $e) {
            $ownerName = $this->members->findByEmail($e->email)[0]->name ?? $e->email;

            return $this->page->show($request, $response, $actor, '', $form->taken($e->email, $ownerName), status: 422);
        }

        return $this->page->answer($request, $response, $actor, '', $toastKey, ['name' => $details->name]);
    }
}
