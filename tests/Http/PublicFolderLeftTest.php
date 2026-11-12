<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Http;

use ShowOfHands\Http\Action\ThemeAction;
use ShowOfHands\Http\Middleware\PublicFolderLeftMiddleware;

/**
 * A release unpacked over one up to 0.4.0, whose folder public/ is still in the installation.
 */
final class PublicFolderLeftTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $root = $this->dataDir . '/root';
        mkdir($root . '/public', 0o777, true);
        file_put_contents($root . '/public/index.php', '<?php');
        $this->app = $this->createApp(overrides: [PublicFolderLeftMiddleware::class => new PublicFolderLeftMiddleware($root)]);
    }

    public function testEveryPageSaysHowToFinishTheUpdate(): void
    {
        foreach (['/login', '/', '/dev-login'] as $path) {
            $response = $this->request('GET', $path);

            self::assertSame(503, $response->getStatusCode(), $path);
            $html = $this->body($response);
            self::assertStringContainsString('Update nicht abgeschlossen', $html);
            self::assertStringContainsString('Verschiebe public/brand/ nach brand/, stelle das Document Root auf den Installationsordner und lösche dann public/.', $html);
        }
        self::assertSame(503, $this->post('/dev-login', ['email' => 'alex@example.org'])->getStatusCode());
        self::assertFileDoesNotExist($this->dataDir . '/logs/app.log', 'Every request meets it, so it stays out of the log.');
    }

    public function testTheStylesheetStillLoads(): void
    {
        self::assertSame(200, $this->request('GET', ThemeAction::PATH)->getStatusCode());
    }
}
