<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Http\Middleware\PublicFolderLeftException;
use ShowOfHands\Infrastructure\Database\DatabaseAheadException;
use ShowOfHands\Infrastructure\Log\ErrorLog;
use Slim\Exception\HttpBadRequestException;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;

/**
 * Renders 400, 403, 404, 500, and 503 as pages in the app's style, and logs unexpected errors.
 *
 * htmx swaps none of them in. A 403 answers htmx with HX-Refresh instead, because it most often
 * means the session expired: the reloaded page sends the member to the login.
 */
final readonly class ErrorHandler
{
    public function __construct(
        private Renderer $renderer,
        private ResponseFactoryInterface $responses,
        private ErrorLog $log,
        private bool $showDetails,
    ) {}

    public function __invoke(ServerRequestInterface $request, \Throwable $error): ResponseInterface
    {
        [$status, $kind] = match (true) {
            $error instanceof HttpBadRequestException => [400, 'bad_request'],
            $error instanceof CsrfException => [403, 'csrf'],
            $error instanceof HttpForbiddenException => [403, 'forbidden'],
            $error instanceof HttpNotFoundException, $error instanceof HttpMethodNotAllowedException => [404, 'not_found'],
            // A page load writes too, for its lazy maintenance, but the member has nothing to tap again.
            self::isBusy($error) => [503, $request->getMethod() === 'GET' ? 'busy_load' : 'busy'],
            // The way back after an update stopped halfway. Every request meets it, so the log stays out of it.
            $error instanceof DatabaseAheadException => [503, $error->backup === null ? 'ahead_no_backup' : 'ahead'],
            $error instanceof PublicFolderLeftException => [503, 'public_left'],
            default => [500, 'server'],
        };
        if (($status === 500 || $status === 503) && !$error instanceof DatabaseAheadException && !$error instanceof PublicFolderLeftException) {
            $this->log->error($error, $request);
        }
        if ($status === 403 && Htmx::isRequest($request)) {
            return $this->responses->createResponse(403)->withHeader('HX-Refresh', 'true');
        }

        try {
            return $this->renderer->render($request, $this->responses->createResponse(), 'errors/error.html.twig', [
                'kind' => $kind,
                'backup' => $error instanceof DatabaseAheadException ? $error->backup : null,
                'details' => $this->showDetails && $status === 500 ? (string) $error : null,
            ], $status);
        } catch (\Throwable $renderError) {
            $this->log->error($renderError, $request);
            $response = $this->responses->createResponse($status)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->getBody()->write($status === 500 ? 'Internal error.' : 'Error ' . $status . '.');

            return $response;
        }
    }

    /**
     * Whether SQLite gave up waiting for another request's write, after its busy_timeout.
     */
    private static function isBusy(\Throwable $error): bool
    {
        // pdo_sqlite puts SQLite's result code in errorInfo[1]: 5 is SQLITE_BUSY, 6 SQLITE_LOCKED.
        return $error instanceof \PDOException && in_array($error->errorInfo[1] ?? null, [5, 6], true);
    }
}
