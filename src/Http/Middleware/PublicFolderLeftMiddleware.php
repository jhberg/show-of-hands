<?php

declare(strict_types=1);

namespace ShowOfHands\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ShowOfHands\Http\Action\ThemeAction;

/**
 * Stops every page while the folder public/ of releases up to 0.4.0 is still in the installation.
 * Unpacked over such a release, the new one leaves public/ behind, and a document root still pointing
 * there would run the new code through the old front controller, with old assets and without the
 * brand files, which moved to brand/. The stop comes before the database, so nothing migrates yet.
 */
final readonly class PublicFolderLeftMiddleware implements MiddlewareInterface
{
    public function __construct(private string $root) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        // The error page's stylesheet must still load.
        if ($request->getUri()->getPath() !== ThemeAction::PATH && is_file($this->root . '/public/index.php')) {
            throw new PublicFolderLeftException();
        }

        return $handler->handle($request);
    }
}
