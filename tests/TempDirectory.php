<?php

declare(strict_types=1);

namespace ShowOfHands\Tests;

use PHPUnit\Framework\Attributes\After;

/**
 * A fresh directory per test, removed afterwards.
 */
trait TempDirectory
{
    private ?string $tempDir = null;

    protected function tempDir(): string
    {
        if ($this->tempDir === null) {
            $this->tempDir = sys_get_temp_dir() . '/soh-test-' . bin2hex(random_bytes(6));
            mkdir($this->tempDir, 0o777, true);
        }

        return $this->tempDir;
    }

    #[After]
    protected function removeTempDir(): void
    {
        if ($this->tempDir !== null) {
            exec('rm -rf ' . escapeshellarg($this->tempDir));
            $this->tempDir = null;
        }
    }
}
