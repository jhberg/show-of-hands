<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Auth;

/**
 * Google's OAuth 2.0 authorization code flow with PKCE, reduced to the two steps the app needs.
 */
interface GoogleSignIn
{
    /**
     * Builds the URL that sends the visitor to Google's account chooser.
     */
    public function start(): GoogleAuthorization;

    /**
     * Exchanges the code from the callback and reads the account's address from Google's userinfo endpoint.
     *
     * @throws GoogleSignInException when Google refuses the code or cannot be reached
     */
    public function finish(string $code, string $verifier): GoogleAccount;
}
