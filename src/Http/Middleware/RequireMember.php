<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ShowOfHands\Http\Action\LoginAction;
use ShowOfHands\Http\Redirect;

/**
 * Sends visitors without a valid session to the login page, which brings them back afterwards.
 */
final readonly class RequireMember implements MiddlewareInterface
{
    public function __construct(private ResponseFactoryInterface $responses) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (MemberMiddleware::member($request) !== null) {
            return $handler->handle($request);
        }

        return Redirect::to($this->responses->createResponse(), $request, LoginAction::url(self::returnPath($request)));
    }

    /**
     * The page the visitor was on. For an htmx request that is the page in the address bar,
     * not the fragment it asked for.
     */
    private static function returnPath(ServerRequestInterface $request): string
    {
        $uri = $request->getUri();
        $current = $request->getHeaderLine('HX-Current-URL');
        if ($current !== '') {
            $parts = parse_url($current);
            if (is_array($parts) && ($parts['host'] ?? null) === $uri->getHost()) {
                return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
            }

            return '/';
        }

        return $uri->getPath() . ($uri->getQuery() === '' ? '' : '?' . $uri->getQuery());
    }
}
