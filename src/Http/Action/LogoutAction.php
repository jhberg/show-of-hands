<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\SignIn;

final readonly class LogoutAction
{
    public function __construct(private SignIn $signIn) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->signIn->signOut();

        return $response->withStatus(303)->withHeader('Location', '/login');
    }
}
