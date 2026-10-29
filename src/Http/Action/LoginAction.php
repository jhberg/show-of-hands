<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\MemberMiddleware;
use ShowOfHands\Http\Renderer;
use ShowOfHands\Http\ReturnPath;
use ShowOfHands\Http\SignIn;

/**
 * The login page, with the unknown-address error after a failed attempt.
 */
final readonly class LoginAction
{
    public function __construct(
        private Renderer $renderer,
        private ReturnPath $returnPath,
        private SignIn $signIn,
        private bool $googleConfigured,
        private bool $devLogin,
    ) {}

    /**
     * The login page's URL that returns to $return after sign-in.
     */
    public static function url(string $return): string
    {
        return '/login' . self::returnQuery($return);
    }

    private static function returnQuery(string $return): string
    {
        return $return === '/' ? '' : '?return=' . rawurlencode($return);
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $return = $this->returnPath->sanitize($request->getQueryParams()['return'] ?? null);
        if (MemberMiddleware::member($request) !== null) {
            return $response->withStatus(303)->withHeader('Location', $return);
        }
        $query = self::returnQuery($return);

        return $this->renderer->render($request, $response, 'pages/login.html.twig', [
            'error_email' => $this->signIn->takeError(),
            // Without a Google client, development signs in through the dev login, like the mockup's account chooser.
            'google_url' => ($this->devLogin && !$this->googleConfigured ? '/dev-login' : '/auth/google') . $query,
            'dev_login_url' => $this->googleConfigured && $this->devLogin ? '/dev-login' . $query : null,
            'returns_to_poll' => preg_match('#^/polls/\d+(?:[/?]|$)#', $return) === 1,
        ]);
    }
}
