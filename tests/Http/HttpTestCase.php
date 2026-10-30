<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\PollKind;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Http\AppFactory;
use ShowOfHands\Http\DevLoginGuard;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Http\Session;
use ShowOfHands\Infrastructure\Auth\GoogleSignIn;
use ShowOfHands\Infrastructure\Config\ConfigLoader;
use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Infrastructure\Persistence\MemberRepository;
use ShowOfHands\Infrastructure\Persistence\PollRepository;
use ShowOfHands\Infrastructure\Setup\FirstStart;
use ShowOfHands\Paths;
use ShowOfHands\Tests\TempDirectory;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class HttpTestCase extends TestCase
{
    use TempDirectory;

    protected string $dataDir;

    protected Session $session;

    protected FakeGoogleSignIn $google;

    /** @var App<Container> */
    protected App $app;

    protected function setUp(): void
    {
        $this->dataDir = $this->tempDir();
        $this->session = Session::inMemory();
        $this->google = new FakeGoogleSignIn();
        $this->app = $this->createApp();
    }

    /**
     * @param array<string, mixed> $config merged over a valid development config
     * @param bool $prod run as production, with a Google client instead of the dev login
     * @param array<string, mixed> $overrides container entries
     *
     * @return App<Container>
     */
    protected function createApp(array $config = [], bool $prod = false, array $overrides = []): App
    {
        if ($prod) {
            $config = array_replace_recursive(['app' => ['env' => 'prod'], 'google' => ['client_id' => 'id', 'client_secret' => 'secret']], $config);
        }
        $config = ConfigLoader::fromArray(array_replace_recursive([
            'app' => [
                'url' => 'http://localhost:8080',
                'secret' => str_repeat('ab', 32),
                'timezone' => 'Europe/Berlin',
                'env' => 'dev',
            ],
            'brand' => ['name' => 'Example Choir'],
        ], $config));

        return AppFactory::create(new Paths(dirname(__DIR__, 2), $this->dataDir), $config, $overrides + [
            Session::class => $this->session,
            GoogleSignIn::class => $this->google,
            DevLoginGuard::class => new DevLoginGuard(builtInServer: true),
        ]);
    }

    /**
     * Adds a member straight to the database, migrating it first.
     *
     * @param list<string> $emails
     */
    protected function addMember(string $name, array $emails, bool $admin = false, bool $board = false, ?string $group = 'S1'): int
    {
        $this->service(Migrator::class)->migrate();
        $this->service(FirstStart::class)->run();

        return $this->service(MemberRepository::class)->add($name, $emails, $group, $board, $admin);
    }

    /**
     * Adds a poll straight to the database, migrating it first.
     */
    protected function addPoll(
        string $subject = 'Robin Example',
        PollStatus $status = PollStatus::Open,
        PollKind $kind = PollKind::Admission,
        ?string $question = null,
        string $group = 'S1',
        ?string $date = null,
        string $note = '',
    ): int {
        $this->service(Migrator::class)->migrate();
        $this->service(FirstStart::class)->run();

        return $this->service(PollRepository::class)->add($kind, $subject, $question, $group, $date, $note, $status, '2026-01-01T00:00:00Z');
    }

    /**
     * Signs in through the dev login and returns its redirect.
     */
    protected function signIn(string $email, string $return = '/'): ResponseInterface
    {
        return $this->post('/dev-login', ['email' => $email, 'return' => $return]);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    protected function service(string $class): object
    {
        $service = $this->app->getContainer()->get($class);
        assert($service instanceof $class);

        return $service;
    }

    /**
     * A request from the developer's machine: host localhost, peer 127.0.0.1, unless $server says otherwise.
     *
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     * @param array<string, string> $server
     */
    protected function request(string $method, string $uri, array $body = [], array $cookies = [], array $headers = [], array $server = []): ResponseInterface
    {
        if (str_starts_with($uri, '/')) {
            $uri = 'http://localhost:8080' . $uri;
        }
        $request = (new ServerRequestFactory())
            ->createServerRequest($method, $uri, $server + ['REMOTE_ADDR' => '127.0.0.1'])
            ->withCookieParams($cookies);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($body !== []) {
            $request = $request->withParsedBody($body);
        }

        return $this->app->handle($request);
    }

    /**
     * A form POST that carries the session's CSRF token.
     *
     * @param array<string, string> $body
     */
    protected function post(string $uri, array $body = []): ResponseInterface
    {
        return $this->request('POST', $uri, [CsrfMiddleware::FIELD => $this->session->csrfToken()] + $body);
    }

    protected function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
