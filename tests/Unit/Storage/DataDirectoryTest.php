<?php

declare(strict_types=1);

namespace ShowOfHands\Tests\Unit\Storage;

use PHPUnit\Framework\TestCase;
use ShowOfHands\Infrastructure\Database\ConnectionFactory;
use ShowOfHands\Infrastructure\Storage\DataDirectory;
use ShowOfHands\Paths;
use ShowOfHands\Tests\TempDirectory;

final class DataDirectoryTest extends TestCase
{
    use TempDirectory;

    private function paths(string $data): Paths
    {
        return new Paths(dirname(__DIR__, 3), $data);
    }

    public function testAMissingFolderIsFineWhenItsParentIsWritable(): void
    {
        self::assertSame([], DataDirectory::unwritable($this->paths($this->tempDir() . '/data')));
    }

    public function testAWritableFolderWithItsEntriesIsFine(): void
    {
        $data = $this->tempDir() . '/data';
        foreach (['sessions', 'logs', 'cache', 'backup'] as $dir) {
            mkdir("{$data}/{$dir}", 0o700, true);
        }
        touch($data . '/app.sqlite');

        self::assertSame([], DataDirectory::unwritable($this->paths($data)));
    }

    public function testAFileWhereTheFolderBelongsIsNotWritable(): void
    {
        touch($this->tempDir() . '/data');

        self::assertSame([$this->tempDir() . '/data'], DataDirectory::unwritable($this->paths($this->tempDir() . '/data')));
    }

    public function testAMissingFolderThatCannotBeCreatedIsNotWritable(): void
    {
        // A file in place of the parent: not even root can create the folder there.
        touch($this->tempDir() . '/install');

        self::assertSame([$this->tempDir() . '/install/data'], DataDirectory::unwritable($this->paths($this->tempDir() . '/install/data')));
    }

    public function testNamesEachEntryTheAppCannotWrite(): void
    {
        if (posix_geteuid() === 0) {
            self::markTestSkipped('root may write anywhere, so only another user sees the folders as read-only.');
        }
        $data = $this->tempDir() . '/data';
        mkdir($data . '/sessions', 0o700, true);
        mkdir($data . '/logs', 0o700);
        touch($data . '/app.sqlite');
        chmod($data . '/sessions', 0o500);
        chmod($data . '/app.sqlite', 0o400);

        try {
            self::assertSame([$data . '/app.sqlite', $data . '/sessions'], DataDirectory::unwritable($this->paths($data)));
        } finally {
            chmod($data . '/sessions', 0o700);
        }
    }

    public function testTheDatabaseAndItsFolderAreTheAppsAlone(): void
    {
        $database = $this->tempDir() . '/data/app.sqlite';

        ConnectionFactory::open($database)->exec('CREATE TABLE t (x)');

        self::assertSame(0o700, fileperms(dirname($database)) & 0o777);
        self::assertSame(0o600, fileperms($database) & 0o777);
    }
}
