<?php

declare(strict_types=1);

namespace ShowOfHands\Http;

use Slim\Exception\HttpForbiddenException;

/**
 * A form came back without a valid token, most often because its page was open for too long.
 */
final class CsrfException extends HttpForbiddenException {}
