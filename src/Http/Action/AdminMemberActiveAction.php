<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\MembersPage;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Infrastructure\Clock\Clock;
use ShowOfHands\Infrastructure\Config\Config;
use ShowOfHands\Infrastructure\Persistence\DeactivationTooSoonException;
use ShowOfHands\Infrastructure\Persistence\MemberActivation;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpNotFoundException;

/**
 * Sets a member inactive or active again (POST /admin/members/{id}/active, active=0 or 1). The
 * open polls count them from then on, or no longer. A member who took part in an open poll, or
 * in a poll closed today, stays active until that poll is out of reach.
 */
final readonly class AdminMemberActiveAction
{
    public function __construct(
        private MembersPage $page,
        private MemberActivation $activation,
        private MemberRepository $members,
        private Clock $clock,
        private Config $config,
    ) {}

    /**
     * @param array{id: string} $args
     */
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $actor = MemberMiddleware::member($request);
        assert($actor !== null);
        $id = (int) $args['id'];
        $body = $request->getParsedBody();
        $body = is_array($body) ? $body : [];
        $query = MembersPage::query($body['q'] ?? null);
        if (!in_array($body['active'] ?? null, ['0', '1'], true)) {
            throw new HttpBadRequestException($request);
        }
        $active = $body['active'] === '1';
        try {
            $member = $this->activation->set($id, $active, $this->clock->now(), new \DateTimeZone($this->config->timezone));
        } catch (DeactivationTooSoonException $e) {
            $member = $this->members->find($id);
            assert($member !== null);
            $toastKey = $e->open ? 'toast.member.deactivate_after_close' : 'toast.member.deactivate_tomorrow';

            return $this->page->answer($request, $response, $actor, $query, $toastKey, ['name' => $member->name]);
        }
        if ($member === null) {
            throw new HttpNotFoundException($request);
        }

        return $this->page->answer($request, $response, $actor, $query, $active ? 'toast.member.activated' : 'toast.member.deactivated', ['name' => $member->name]);
    }
}
