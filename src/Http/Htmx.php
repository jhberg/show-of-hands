<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Psr\Http\Message\ServerRequestInterface;

final class Htmx
{
    /**
     * Whether htmx asks for a fragment. A history restore asks for the whole page, though htmx
     * sends it, because nothing on the page is left to swap into.
     */
    public static function isRequest(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('HX-Request') === 'true' && $request->getHeaderLine('HX-History-Restore-Request') !== 'true';
    }
}
