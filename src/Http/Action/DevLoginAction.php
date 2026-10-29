<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Action;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\DevLoginGuard;
use ShowOfHands\Http\Renderer;
use ShowOfHands\Http\ReturnPath;
use ShowOfHands\Http\SignIn;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use Slim\Exception\HttpNotFoundException;
use Symfony\Component\Translation\Translator;

/**
 * Development only: sign in as any member, or with any address, without Google.
 * The route exists only when app.env is 'dev', and the guard checks every request.
 */
final readonly class DevLoginAction
{
    public function __construct(
        private DevLoginGuard $guard,
        private Renderer $renderer,
        private ReturnPath $returnPath,
        private SignIn $signIn,
        private MemberRepository $members,
        private Translator $translator,
    ) {}

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->guard->allows($request)) {
            throw new HttpNotFoundException($request);
        }
        if ($request->getMethod() === 'POST') {
            $body = (array) $request->getParsedBody();
            $return = $this->returnPath->sanitize($body['return'] ?? null);
            $email = $body['email'] ?? null;
            $signedIn = is_string($email) && trim($email) !== '' && $this->signIn->withEmail($email);

            return $response->withStatus(303)->withHeader('Location', $signedIn ? $return : LoginAction::url($return));
        }

        return $this->renderer->render($request, $response, 'pages/dev_login.html.twig', [
            'accounts' => $this->members->allWithEmails($this->translator->getLocale()),
            'return' => $this->returnPath->sanitize($request->getQueryParams()['return'] ?? null),
        ]);
    }
}
