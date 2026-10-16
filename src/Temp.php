<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * Temporary files and directories, removed on the way out.
 *
 * Kept in one place because the removal is the part that is easy to forget, and
 * because a downloader that leaves partial archives behind fills a disk slowly
 * enough that nobody notices until it matters.
 */
final class Temp
{
    /** @var list<string> */
    private static array $created = [];

    /**
     * A temporary file that does not yet exist.
     *
     * `tempnam` creates the file, which is wanted: the name is then guaranteed
     * to be unique, so two concurrent runs cannot collide.
     */
    public static function file(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix . '-');
        if ($path === false) {
            throw new Failure('could not create a temporary file');
        }

        self::$created[] = $path;

        return $path;
    }

    public static function directory(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix . '-');
        if ($path === false) {
            throw new Failure('could not create a temporary directory');
        }

        // `tempnam` made a file; a directory of the same name cannot exist.
        unlink($path);
        if (!mkdir($path, 0o700)) {
            throw new Failure(sprintf('could not create %s', $path));
        }

        self::$created[] = $path;

        return $path;
    }

    public static function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);
        } elseif (is_dir($path)) {
            self::removeTree($path);
        }

        self::$created = array_values(array_filter(
            self::$created,
            static fn (string $kept): bool => $kept !== $path,
        ));
    }

    /**
     * Remove everything this class still holds. Called from `Cli::main`'s
     * finally, so a failure part-way through leaves no litter.
     */
    public static function cleanup(): void
    {
        foreach (array_reverse(self::$created) as $path) {
            self::remove($path);
        }
    }

    private static function removeTree(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
