<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use ShowOfHands\Http\Middleware\CsrfMiddleware;
use ShowOfHands\Http\Middleware\LocaleMiddleware;

/**
 * Access logs record the size of every response. A Yes and a No must therefore give responses of
 * the same length: the poll page, its live refresh, and the answer to the vote with its toast.
 * Uncompressed, that is: the content differs, so gzip may still give sizes a few bytes apart.
 */
final class ResponseSizeTest extends HttpTestCase
{
    private string $locale = 'de';

    /** Two polls that differ only in their id, one digit each, so a member's page is the same in both. */
    private int $yesPoll;

    private int $noPoll;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addMember('Kim Example', ['kim@example.org']);
        $this->addMember('Lou Example', ['lou@example.org']);
        $this->yesPoll = $this->addPoll();
        $this->noPoll = $this->addPoll();
        $this->signIn('kim@example.org');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        yield 'German' => ['de'];
        yield 'English' => ['en'];
    }

    #[DataProvider('locales')]
    public function testAYesAndANoGiveResponsesOfTheSameLength(string $locale): void
    {
        $this->locale = $locale;

        // Without JavaScript: the redirect, then the page with the toast that names the choice.
        $this->assertSameLength(
            fn(int $poll, string $choice): ResponseInterface => $this->vote($poll, $choice),
            fn(int $poll): ResponseInterface => $this->get("/polls/{$poll}"),
        );
        // The page again without a toast, and the live refresh.
        $this->assertSameLength(fn(int $poll): ResponseInterface => $this->get("/polls/{$poll}"));
        $this->assertSameLength(fn(int $poll): ResponseInterface => $this->get("/polls/{$poll}/live", htmx: true));
        // With htmx: the fresh <main> and the toast in HX-Trigger, for the same choice again, then the other.
        $this->assertSameLength(fn(int $poll, string $choice): ResponseInterface => $this->vote($poll, $choice, htmx: true));
        $this->assertSameLength(fn(int $poll, string $choice): ResponseInterface => $this->vote($poll, $choice === 'yes' ? 'no' : 'yes', htmx: true));
    }

    /**
     * Runs the steps for the Yes poll with Yes and for the No poll with No, and compares the last
     * step's body and headers.
     *
     * @param callable(int, string): ResponseInterface ...$steps
     */
    private function assertSameLength(callable ...$steps): void
    {
        $sizes = [];
        foreach (['yes' => $this->yesPoll, 'no' => $this->noPoll] as $choice => $poll) {
            foreach ($steps as $step) {
                $response = $step($poll, $choice);
            }
            assert(isset($response));
            self::assertContains($response->getStatusCode(), [200, 303]);
            $sizes[$choice] = [strlen($this->body($response)), strlen($response->getHeaderLine('HX-Trigger'))];
        }
        self::assertSame($sizes['yes'], $sizes['no']);
    }

    private function vote(int $poll, string $choice, bool $htmx = false): ResponseInterface
    {
        return $this->request(
            'POST',
            "/polls/{$poll}/ballot",
            [CsrfMiddleware::FIELD => $this->session->csrfToken(), 'choice' => $choice],
            [LocaleMiddleware::COOKIE => $this->locale],
            $htmx ? ['HX-Request' => 'true'] : [],
        );
    }

    private function get(string $uri, bool $htmx = false): ResponseInterface
    {
        return $this->request('GET', $uri, cookies: [LocaleMiddleware::COOKIE => $this->locale], headers: $htmx ? ['HX-Request' => 'true'] : []);
    }
}
