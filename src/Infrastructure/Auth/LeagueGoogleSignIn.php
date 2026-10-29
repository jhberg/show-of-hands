<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Auth;

use League\OAuth2\Client\Token\AccessToken;

final readonly class LeagueGoogleSignIn implements GoogleSignIn
{
    public const CALLBACK_PATH = '/auth/google/callback';

    private GoogleProvider $provider;

    public function __construct(string $clientId, string $clientSecret, string $appUrl)
    {
        $this->provider = new GoogleProvider([
            'clientId' => $clientId,
            'clientSecret' => $clientSecret,
            'redirectUri' => $appUrl . self::CALLBACK_PATH,
            // No hd parameter: it would hide the private accounts admins registered.
            'prompt' => 'select_account',
        ]);
    }

    public function start(): GoogleAuthorization
    {
        $url = $this->provider->getAuthorizationUrl();

        return new GoogleAuthorization($url, $this->provider->getState(), (string) $this->provider->getPkceCode());
    }

    public function finish(string $code, string $verifier): GoogleAccount
    {
        try {
            $this->provider->setPkceCode($verifier);
            $token = $this->provider->getAccessToken('authorization_code', ['code' => $code]);
            assert($token instanceof AccessToken);
            $data = $this->provider->getResourceOwner($token)->toArray();
        } catch (\Throwable $e) {
            // Google's error code (invalid_client, redirect_uri_mismatch, invalid_grant) or a connection
            // error. The code travels only in the POST body, so neither message contains it.
            throw new GoogleSignInException('Google sign-in failed: ' . $e::class . ': ' . $e->getMessage(), 0, $e);
        }
        $email = $data['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw new GoogleSignInException('Google returned no email address.');
        }

        return new GoogleAccount($email, ($data['email_verified'] ?? false) === true);
    }
}
