<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Paths;
use ShowOfHands\Tests\TempDirectory;

final class PathsTest extends TestCase
{
    use TempDirectory;

    public function testEachReleaseCachesIntoItsOwnDirectory(): void
    {
        file_put_contents($this->tempDir() . '/VERSION', "1.2.0\n");
        $paths = new Paths($this->tempDir());

        self::assertSame('1.2.0', $paths->release);
        self::assertSame($this->tempDir() . '/data/cache/1.2.0/twig', $paths->templateCache());
        self::assertSame($this->tempDir() . '/data/cache/1.2.0/translations', $paths->translationCache());
        self::assertSame($this->tempDir() . '/data/cache/1.2.0/container', $paths->containerCache());
    }

    public function testAVersionThatIsNoPlainNameFallsBackToDev(): void
    {
        foreach (['..', '../x', '.hidden', ''] as $version) {
            file_put_contents($this->tempDir() . '/VERSION', $version . "\n");

            self::assertSame($this->tempDir() . '/data/cache/dev', (new Paths($this->tempDir()))->cache(), $version);
        }
    }

    public function testACheckoutUsesTheDevCacheAndCompilesNoContainer(): void
    {
        $paths = new Paths($this->tempDir());

        self::assertNull($paths->release);
        self::assertSame($this->tempDir() . '/data/cache/dev/twig', $paths->templateCache());
        self::assertNull($paths->containerCache());
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
