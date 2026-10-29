<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Flash;
use ShowOfHands\Http\Session;
use ShowOfHands\Http\SignIn;
use ShowOfHands\Infrastructure\Auth\GoogleSignIn;
use ShowOfHands\Infrastructure\Auth\GoogleSignInException;
use ShowOfHands\Infrastructure\Log\ErrorLog;

/**
 * Google sends the visitor back here with a code. A top-level GET, so the SameSite=Lax
 * session cookie arrives on phones too.
 */
final readonly class GoogleCallbackAction
{
    public function __construct(
        private GoogleSignIn $google,
        private Session $session,
        private SignIn $signIn,
        private Flash $flash,
        private ErrorLog $log,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $query = $request->getQueryParams();
        // One use only: a reloaded callback or a replayed code finds nothing to match.
        $pending = $this->session->take(GoogleStartAction::SESSION_KEY);
        if (!is_array($pending) || !is_string($pending['state'] ?? null) || !is_string($pending['verifier'] ?? null) || !is_string($pending['return'] ?? null)) {
            return $this->failed($response, '/');
        }
        $return = $pending['return'];
        $code = $query['code'] ?? null;
        // Without a code, the visitor cancelled at Google. That needs no toast.
        if (!is_string($code) || $code === '') {
            return $response->withStatus(303)->withHeader('Location', LoginAction::url($return));
        }
        $state = $query['state'] ?? null;
        if (!is_string($state) || !hash_equals($pending['state'], $state)) {
            return $this->failed($response, $return);
        }
        try {
            $account = $this->google->finish($code, $pending['verifier']);
        } catch (GoogleSignInException $e) {
            $this->log->error($e);

            return $this->failed($response, $return);
        }
        $location = $this->signIn->withEmail($account->email, $account->verified) ? $return : LoginAction::url($return);

        return $response->withStatus(303)->withHeader('Location', $location);
    }

    private function failed(ResponseInterface $response, string $return): ResponseInterface
    {
        $this->flash->toast('toast.sign_in_failed');

        return $response->withStatus(303)->withHeader('Location', LoginAction::url($return));
    }
}
