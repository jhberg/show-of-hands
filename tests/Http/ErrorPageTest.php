<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Http\Middleware\LocaleMiddleware;
use ShowOfHands\Http\ReturnPath;

final class ErrorPageTest extends HttpTestCase
{
    public function testRendersNotFoundInTheAppStyle(): void
    {
        $response = $this->request('GET', '/does-not-exist', cookies: [LocaleMiddleware::COOKIE => 'en']);

        self::assertSame(404, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('Page not found', $html);
        self::assertStringContainsString('<html lang="en">', $html);
        self::assertStringContainsString('value="de" aria-label="Language: English. Switch to Deutsch"', $html);
        self::assertStringContainsString('class="top"', $html);
        self::assertSame('no-store', $response->getHeaderLine('Cache-Control'));
        self::assertNotSame('', $response->getHeaderLine('Content-Security-Policy'));
    }

    public function testTreatsAWrongMethodAsNotFound(): void
    {
        self::assertSame(404, $this->request('GET', '/locale')->getStatusCode());
    }

    public function testRendersAndLogsAServerErrorWithoutRequestData(): void
    {
        $this->app = $this->createApp(prod: true, overrides: [
            ReturnPath::class => static fn(): never => throw new \RuntimeException('Broken on purpose.'),
        ]);

        $response = $this->post('/locale', ['locale' => 'en', 'return' => '/secret-value']);

        self::assertSame(500, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('Etwas ist schiefgelaufen', $html);
        self::assertStringNotContainsString('class="details"', $html);
        self::assertStringNotContainsString('Broken on purpose', $html);
        $log = (string) file_get_contents($this->dataDir . '/logs/app.log');
        self::assertStringContainsString('RuntimeException: Broken on purpose.', $log);
        self::assertStringNotContainsString('secret-value', $log);
    }

    public function testShowsErrorDetailsInDevelopment(): void
    {
        $this->app = $this->createApp(overrides: [
            ReturnPath::class => static fn(): never => throw new \RuntimeException('Broken on purpose.'),
        ]);

        $html = $this->body($this->post('/locale', ['locale' => 'en']));

        self::assertStringContainsString('class="details"', $html);
        self::assertStringContainsString('Broken on purpose.', $html);
    }

    public function testAnExpiredFormReloadsThePageForHtmx(): void
    {
        $poll = $this->addPoll();
        $sent = [CsrfMiddleware::FIELD => 'from-an-expired-session', 'choice' => 'yes'];

        $page = $this->request('POST', "/polls/{$poll}/ballot", $sent);
        $htmx = $this->request('POST', "/polls/{$poll}/ballot", $sent, headers: ['HX-Request' => 'true']);

        self::assertSame(403, $page->getStatusCode());
        self::assertStringContainsString('Formular abgelaufen', $this->body($page));
        self::assertSame(403, $htmx->getStatusCode());
        self::assertSame('true', $htmx->getHeaderLine('HX-Refresh'));
    }

    public function testAVoteDuringAnotherWriteAnswersBusyInsteadOfAServerError(): void
    {
        $this->addMember('Robin Example', ['robin@example.org']);
        $poll = $this->addPoll();
        $this->signIn('robin@example.org');
        // Another request holds the write lock, and this one stops waiting at once instead of after busy_timeout.
        $this->service(\PDO::class)->exec('PRAGMA busy_timeout = 0');
        $other = new \PDO('sqlite:' . $this->dataDir . '/app.sqlite');
        $other->exec('BEGIN IMMEDIATE');

        $page = $this->request('POST', "/polls/{$poll}/ballot", [CsrfMiddleware::FIELD => $this->session->csrfToken(), 'choice' => 'yes']);
        $htmx = $this->post("/polls/{$poll}/ballot", ['choice' => 'yes'], htmx: true);
        $other->exec('ROLLBACK');

        self::assertSame(503, $page->getStatusCode());
        $html = $this->body($page);
        self::assertStringContainsString('Gerade ist viel los', $html);
        self::assertStringContainsString('Nicht gespeichert. Geh zurück und tippe noch einmal.', $html);
        self::assertStringContainsString('data-not-saved="Nicht gespeichert, bitte nochmal tippen."', $html);
        self::assertSame(503, $htmx->getStatusCode());
        self::assertSame('', $htmx->getHeaderLine('HX-Trigger'));
        self::assertStringContainsString('database is locked', (string) file_get_contents($this->dataDir . '/logs/app.log'));
        $ballots = $this->service(\PDO::class)->query('SELECT COUNT(*) FROM ballots');
        self::assertNotFalse($ballots);
        self::assertSame(0, (int) $ballots->fetchColumn());
    }

    public function testAPageLoadDuringAnotherWriteAsksToReload(): void
    {
        // The first request after setup claims the lazy maintenance, which writes.
        $this->addPoll();
        $this->service(\PDO::class)->exec('PRAGMA busy_timeout = 0');
        $other = new \PDO('sqlite:' . $this->dataDir . '/app.sqlite');
        $other->exec('BEGIN IMMEDIATE');

        $response = $this->request('GET', '/login');
        $other->exec('ROLLBACK');

        self::assertSame(503, $response->getStatusCode());
        $html = $this->body($response);
        self::assertStringContainsString('Die Seite konnte gerade nicht geladen werden.', $html);
        self::assertStringNotContainsString('Nicht gespeichert. Geh zurück', $html);
    }
}
