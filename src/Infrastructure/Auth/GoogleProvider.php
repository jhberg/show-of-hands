<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Auth;

use League\OAuth2\Client\Provider\Google;

/**
 * Google's provider with PKCE and without the profile scope. The app needs the address only.
 */
final class GoogleProvider extends Google
{
    /**
     * @return list<string>
     */
    protected function getDefaultScopes(): array
    {
        return ['openid', 'email'];
    }

    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }
}
