<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Http\AppFactory;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Http\Session;
use ShowOfHands\Infrastructure\Config\ConfigLoader;
use ShowOfHands\Paths;
use ShowOfHands\Tests\TempDirectory;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

abstract class HttpTestCase extends TestCase
{
    use TempDirectory;

    protected string $dataDir;

    protected Session $session;

    /** @var App<Container> */
    protected App $app;

    protected function setUp(): void
    {
        $this->dataDir = $this->tempDir();
        $this->session = Session::inMemory();
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

        return AppFactory::create(new Paths(dirname(__DIR__, 2), $this->dataDir), $config, [Session::class => $this->session] + $overrides);
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $cookies
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $uri, array $body = [], array $cookies = [], array $headers = []): ResponseInterface
    {
        $request = (new ServerRequestFactory())->createServerRequest($method, $uri)->withCookieParams($cookies);
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
    protected function post(string $uri, array $body): ResponseInterface
    {
        return $this->request('POST', $uri, [CsrfMiddleware::FIELD => $this->session->csrfToken()] + $body);
    }

    protected function body(ResponseInterface $response): string
    {
        return (string) $response->getBody();
    }
}
