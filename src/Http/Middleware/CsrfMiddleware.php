<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ShowOfHands\Http\CsrfException;
use ShowOfHands\Http\Session;

/**
 * Rejects every POST whose _csrf field does not match the session's token.
 */
final readonly class CsrfMiddleware implements MiddlewareInterface
{
    public const FIELD = '_csrf';

    public function __construct(private Session $session) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if ($request->getMethod() !== 'GET' && $request->getMethod() !== 'HEAD') {
            $body = $request->getParsedBody();
            $sent = is_array($body) ? ($body[self::FIELD] ?? null) : null;
            if (!$this->session->isValidCsrfToken($sent)) {
                throw new CsrfException($request);
            }
        }

        return $handler->handle($request);
    }
}
