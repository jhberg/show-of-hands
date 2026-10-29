<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Infrastructure\Log\ErrorLog;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpMethodNotAllowedException;
use Slim\Exception\HttpNotFoundException;

/**
 * Renders 404, 403, and 500 as pages in the app's style, and logs unexpected errors.
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
            $error instanceof CsrfException => [403, 'csrf'],
            $error instanceof HttpForbiddenException => [403, 'forbidden'],
            $error instanceof HttpNotFoundException, $error instanceof HttpMethodNotAllowedException => [404, 'not_found'],
            default => [500, 'server'],
        };
        if ($status === 500) {
            $this->log->error($error);
        }

        try {
            return $this->renderer->render($request, $this->responses->createResponse(), 'errors/error.html.twig', [
                'kind' => $kind,
                'details' => $this->showDetails && $status === 500 ? (string) $error : null,
            ], $status);
        } catch (\Throwable $renderError) {
            $this->log->error($renderError);
            $response = $this->responses->createResponse($status)->withHeader('Content-Type', 'text/plain; charset=utf-8');
            $response->getBody()->write($status === 500 ? 'Internal error.' : 'Error ' . $status . '.');

            return $response;
        }
    }
}
