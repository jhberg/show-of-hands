<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\ReturnPath;
use ShowOfHands\Http\Session;
use ShowOfHands\Infrastructure\Auth\GoogleSignIn;

/**
 * Sends the visitor to Google's account chooser. The session keeps what the callback must match.
 */
final readonly class GoogleStartAction
{
    public const SESSION_KEY = 'oauth';

    public function __construct(
        private GoogleSignIn $google,
        private Session $session,
        private ReturnPath $returnPath,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $authorization = $this->google->start();
        $this->session->set(self::SESSION_KEY, [
            'state' => $authorization->state,
            'verifier' => $authorization->verifier,
            'return' => $this->returnPath->sanitize($request->getQueryParams()['return'] ?? null),
        ]);

        return $response->withStatus(303)->withHeader('Location', $authorization->url);
    }
}
