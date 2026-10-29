<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ShowOfHands\Http\Redirect;
use Slim\Exception\HttpForbiddenException;

/**
 * Guards board and admin routes. Runs inside RequireMember, so a member is always signed in here.
 *
 * Members who open an admin page land on the home page, the way the header offers them no
 * admin link. A board action from someone without the role is refused with 403.
 */
final readonly class RequireRole implements MiddlewareInterface
{
    private function __construct(
        private bool $admin,
        private ResponseFactoryInterface $responses,
    ) {}

    public static function admin(ResponseFactoryInterface $responses): self
    {
        return new self(true, $responses);
    }

    public static function board(ResponseFactoryInterface $responses): self
    {
        return new self(false, $responses);
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $member = MemberMiddleware::member($request);
        if ($member === null) {
            throw new \LogicException('RequireRole must run inside RequireMember.');
        }
        if ($this->admin && !$member->isAdmin) {
            return Redirect::to($this->responses->createResponse(), $request, '/');
        }
        if (!$this->admin && !$member->isBoard) {
            throw new HttpForbiddenException($request);
        }

        return $handler->handle($request);
    }
}
