<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Domain\PollStatus;
use ShowOfHands\Http\Middleware\SecurityHeadersMiddleware;

/**
 * Every response the app sends carries the security headers, and no page needs more than the
 * CSP allows: no inline style, no inline script, no event handler attribute.
 */
final class SecurityHeadersTest extends HttpTestCase
{
    private int $open;

    private int $closed;

    private int $draft;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addMember('Alex Admin', ['alex@example.org'], admin: true, board: true);
        $this->addMember('Kim Member', ['kim@example.org']);
        $this->open = $this->addPoll('Robin Example');
        $this->closed = $this->addPoll('Sam Example');
        $this->draft = $this->addPoll('Draft Example', PollStatus::Draft);
        $this->signIn('alex@example.org');
        $this->post('/polls/' . $this->open . '/ballot', ['choice' => 'yes']);
        $this->post('/polls/' . $this->closed . '/ballot', ['choice' => 'no']);
        $this->post('/polls/' . $this->closed . '/close');
    }

    /**
     * Every page an admin can open, with its inline states.
     *
     * @return list<string>
     */
    private function pages(): array
    {
        return [
            '/',
            '/polls/' . $this->open,
            '/polls/' . $this->open . '?delegate=1',
            '/polls/' . $this->open . '?confirm=close',
            '/polls/' . $this->closed,
            '/polls/999',
            '/admin/polls',
            '/admin/polls/new',
            '/admin/polls/' . $this->open,
            '/admin/polls/' . $this->open . '/edit',
            '/admin/polls/' . $this->closed,
            '/admin/polls/' . $this->closed . '?confirm=reopen',
            '/admin/polls/' . $this->draft,
            '/admin/polls/' . $this->draft . '?confirm=delete',
            '/admin/polls/' . $this->draft . '/edit',
            '/admin/polls/999',
            '/admin/members',
            '/admin/members?add=1',
            '/admin/members?edit=1',
            '/admin/members?delete=2',
            '/admin/members?q=kim',
            '/admin/settings',
            '/dev-login',
            '/no-such-page',
        ];
    }

    public function testEveryPageSendsTheHeadersAndNeedsNothingTheCspForbids(): void
    {
        foreach ($this->pages() as $page) {
            foreach ([false, true] as $htmx) {
                $response = $this->request('GET', $page, headers: $htmx ? ['HX-Request' => 'true'] : []);
                $label = $page . ($htmx ? ' (htmx)' : '');

                self::assertContains($response->getStatusCode(), [200, 404], $label);
                $this->assertSecurityHeaders($response, $label);
                self::assertSame('no-store', $response->getHeaderLine('Cache-Control'), $label);
                self::assertFalse($response->hasHeader('Strict-Transport-Security'), $label . ': HSTS only over HTTPS');
                $this->assertNoInlineCode($this->body($response), $label);
            }
        }
    }

    public function testTheLiveFragmentSendsTheHeaders(): void
    {
        $response = $this->request('GET', '/polls/' . $this->open . '/live', headers: ['HX-Request' => 'true']);

        self::assertSame(200, $response->getStatusCode());
        $this->assertSecurityHeaders($response, 'live');
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertNoInlineCode($this->body($response), 'live');
    }

    public function testRedirectsAndTheThemeSendTheHeaders(): void
    {
        $redirect = $this->post('/polls/' . $this->open . '/ballot', ['choice' => 'no']);
        self::assertSame(303, $redirect->getStatusCode());
        $this->assertSecurityHeaders($redirect, 'ballot redirect');
        self::assertSame('no-store', $redirect->getHeaderLine('Cache-Control'));

        $theme = $this->request('GET', '/theme.css');
        $this->assertSecurityHeaders($theme, 'theme');
        self::assertStringContainsString('immutable', $theme->getHeaderLine('Cache-Control'), 'The theme is the one response a cache may keep.');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function signedOutPages(): iterable
    {
        yield 'login' => ['/login'];
        yield 'home redirect' => ['/'];
        yield 'not found' => ['/no-such-page'];
    }

    #[DataProvider('signedOutPages')]
    public function testSendsHstsOnEveryResponseOverHttps(string $page): void
    {
        $this->app = $this->createApp(['app' => ['url' => 'https://vote.example.org']], prod: true);

        $response = $this->request('GET', 'https://vote.example.org' . $page, server: ['HTTPS' => 'on']);

        self::assertSame('max-age=31536000', $response->getHeaderLine('Strict-Transport-Security'));
        $this->assertSecurityHeaders($response, $page);
        $this->assertNoInlineCode($this->body($response), $page);
    }

    private function assertSecurityHeaders(ResponseInterface $response, string $label): void
    {
        self::assertSame(SecurityHeadersMiddleware::CSP, $response->getHeaderLine('Content-Security-Policy'), $label);
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'), $label);
        self::assertSame('DENY', $response->getHeaderLine('X-Frame-Options'), $label);
        self::assertSame('same-origin', $response->getHeaderLine('Referrer-Policy'), $label);
        self::assertSame('same-origin', $response->getHeaderLine('Cross-Origin-Opener-Policy'), $label);
        self::assertNotSame('', $response->getHeaderLine('Permissions-Policy'), $label);
    }

    private function assertNoInlineCode(string $html, string $label): void
    {
        self::assertDoesNotMatchRegularExpression('/\sstyle=/', $html, $label);
        self::assertDoesNotMatchRegularExpression('/<style/', $html, $label);
        self::assertDoesNotMatchRegularExpression('/<script(?![^>]*\ssrc=)/', $html, $label);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $html, $label);
        self::assertDoesNotMatchRegularExpression('/javascript:/i', $html, $label);
        self::assertDoesNotMatchRegularExpression('/\shx-on/', $html, $label . ': hx-on evaluates code, which the CSP forbids.');
    }
}
