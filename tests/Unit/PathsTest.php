<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Paths;
use ShowOfHands\Tests\TempDirectory;

final class PathsTest extends TestCase
{
    use TempDirectory;

    public function testEachReleaseCompilesTemplatesIntoItsOwnDirectory(): void
    {
        file_put_contents($this->tempDir() . '/VERSION', "1.2.0\n");

        self::assertSame($this->tempDir() . '/data/cache/twig/1.2.0', (new Paths($this->tempDir()))->templateCache());
    }

    public function testAVersionThatIsNoPlainNameFallsBackToDev(): void
    {
        foreach (['..', '../x', '.hidden', ''] as $version) {
            file_put_contents($this->tempDir() . '/VERSION', $version . "\n");

            self::assertSame($this->tempDir() . '/data/cache/twig/dev', (new Paths($this->tempDir()))->templateCache(), $version);
        }
    }

    public function testACheckoutWithoutReleaseUsesTheDevCache(): void
    {
        self::assertSame($this->tempDir() . '/data/cache/twig/dev', (new Paths($this->tempDir()))->templateCache());
    }

    public function testAReleaseListsItsMigrations(): void
    {
        file_put_contents($this->tempDir() . '/MIGRATIONS.txt', "0001_init.sql\n0002_polls.sql\n");

        self::assertSame(['0001_init.sql', '0002_polls.sql'], (new Paths($this->tempDir()))->releaseMigrations());
    }

    public function testACheckoutListsNoMigrations(): void
    {
        self::assertNull((new Paths($this->tempDir()))->releaseMigrations());
    }
}
