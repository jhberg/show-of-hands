<?php

declare(strict_types=1);

namespace ShowOfHands\Infrastructure\Storage;

use ShowOfHands\Infrastructure\Database\Migrator;
use ShowOfHands\Paths;

/**
 * The data directory: the database, sessions, log, backups, and caches. On a shared web space
 * other accounts may share the server, so what the app creates there only its own user may read.
 */
final class DataDirectory
{
    /**
     * Creates a directory and its missing parents, readable by the app's user only.
     *
     * @throws \RuntimeException when the directory cannot be created
     */
    public static function create(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create the directory {$dir}.");
        }
    }

    /**
     * Restricts a file in data/ to the app's own user. Some hosts forbid chmod; the file then
     * keeps its mode.
     */
    public static function restrict(string $file): void
    {
        @chmod($file, 0o600);
    }

    /**
     * The paths in data/ the app needs to write but cannot: data/ itself, which it creates when
     * missing, then each entry that exists.
     *
     * @return list<string>
     */
    public static function unwritable(Paths $paths): array
    {
        $data = $paths->data();
        if (!file_exists($data)) {
            $parent = dirname($data);
            while (!file_exists($parent) && dirname($parent) !== $parent) {
                $parent = dirname($parent);
            }

            return is_dir($parent) && is_writable($parent) ? [] : [$data];
        }
        if (!is_dir($data) || !is_writable($data)) {
            return [$data];
        }
        $unwritable = [];
        foreach ([$paths->database(), $paths->sessions(), dirname($paths->log()), dirname($paths->cache()), $paths->cache(), $data . '/' . Migrator::BACKUP_DIR] as $path) {
            if (file_exists($path) && !is_writable($path)) {
                $unwritable[] = $path;
            }
        }

        return $unwritable;
    }
}
