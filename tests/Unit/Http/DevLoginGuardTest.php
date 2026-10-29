<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ShowOfHands\Http\DevLoginGuard;
use Slim\Psr7\Factory\ServerRequestFactory;

final class DevLoginGuardTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function requests(): iterable
    {
        yield 'localhost' => ['http://localhost:8080/dev-login', '127.0.0.1', true];
        yield 'loopback range' => ['http://127.0.0.1/dev-login', '127.8.9.10', true];
        yield 'IPv6 loopback' => ['http://[::1]:8080/dev-login', '::1', true];
        yield 'trusted gateway' => ['http://localhost/dev-login', '172.21.0.1', true];
        yield 'public host' => ['http://vote.example.org/dev-login', '127.0.0.1', false];
        yield 'public peer' => ['http://localhost/dev-login', '203.0.113.7', false];
        yield 'just outside the range' => ['http://localhost/dev-login', '172.32.0.1', false];
        yield 'IPv6 peer' => ['http://localhost/dev-login', '2001:db8::1', false];
        yield 'garbage peer' => ['http://localhost/dev-login', 'not-an-ip', false];
    }

    #[DataProvider('requests')]
    public function testAllowsOnlyLoopbackAndTrustedPeers(string $uri, string $peer, bool $allowed): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', $uri, ['REMOTE_ADDR' => $peer]);

        self::assertSame($allowed, (new DevLoginGuard(true, ['172.16.0.0/12']))->allows($request));
    }

    public function testRefusesARequestWithoutPeer(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dev-login');

        self::assertFalse((new DevLoginGuard(true))->allows($request));
    }

    public function testRefusesEverythingOutsidePhpsBuiltInServer(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'http://localhost/dev-login', ['REMOTE_ADDR' => '127.0.0.1']);

        self::assertFalse((new DevLoginGuard(false))->allows($request));
        self::assertFalse(DevLoginGuard::fromEnvironment()->allows($request), 'PHPUnit runs on the CLI, not on the built-in server.');
    }
}
