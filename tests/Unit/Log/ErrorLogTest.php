<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Log;

use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;
use ShowOfHands\Infrastructure\Log\ErrorLog;
use ShowOfHands\Tests\TempDirectory;
use Slim\Psr7\Factory\ServerRequestFactory;

final class ErrorLogTest extends TestCase
{
    use TempDirectory;

    private static function request(): ServerRequestInterface
    {
        return (new ServerRequestFactory())->createServerRequest('GET', 'https://vote.example.org/polls/1/live');
    }

    private function file(): string
    {
        return $this->tempDir() . '/logs/app.log';
    }

    public function testNamesTheRequestsMethodAndPathButNotItsQueryOrBody(): void
    {
        $request = (new ServerRequestFactory())
            ->createServerRequest('POST', 'https://vote.example.org/polls/3/ballot?choice=yes')
            ->withParsedBody(['choice' => 'no']);

        (new ErrorLog($this->file()))->error(new \RuntimeException('Migration 0011_x.sql failed'), $request);

        $line = (string) file_get_contents($this->file());
        self::assertMatchesRegularExpression('#^\[[^\]]+\] POST /polls/3/ballot RuntimeException: Migration 0011_x\.sql failed at .+:\d+\n$#', $line);
        self::assertStringNotContainsString('choice', $line);
        self::assertStringNotContainsString('yes', $line);
    }

    public function testACraftedPathCannotForgeALine(): void
    {
        $request = (new ServerRequestFactory())->createServerRequest('GET', 'https://vote.example.org/a%0Ab');

        (new ErrorLog($this->file()))->error(new \RuntimeException('x'), $request);

        self::assertCount(1, file($this->file()) ?: []);
    }

    public function testCreatesItsFolderForTheAppsUserOnly(): void
    {
        (new ErrorLog($this->file()))->error(new \RuntimeException('x'), self::request());

        self::assertSame(0o700, fileperms(dirname($this->file())) & 0o777);
    }

    public function testMovesAFullLogAsideKeepingOneOldFile(): void
    {
        $log = new ErrorLog($this->file());
        $log->error(new \RuntimeException('first'), self::request());
        file_put_contents($this->file(), str_repeat('x', ErrorLog::MAX_BYTES), FILE_APPEND);
        file_put_contents($this->file() . '.1', 'older');

        $log->error(new \RuntimeException('second'), self::request());

        self::assertStringContainsString('first', (string) file_get_contents($this->file() . '.1'));
        self::assertStringNotContainsString('older', (string) file_get_contents($this->file() . '.1'));
        $current = (string) file_get_contents($this->file());
        self::assertStringContainsString('second', $current);
        self::assertStringNotContainsString('first', $current);
    }

    public function testKeepsWritingALogBelowTheLimit(): void
    {
        $log = new ErrorLog($this->file());
        $log->error(new \RuntimeException('first'), self::request());
        $log->error(new \RuntimeException('second'), self::request());

        self::assertCount(2, file($this->file()) ?: []);
        self::assertFileDoesNotExist($this->file() . '.1');
    }
}
