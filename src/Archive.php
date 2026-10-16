<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * Extracting an archive, and refusing the archives that should not be
 * extracted.
 *
 * This is the part of a downloader that is a security boundary rather than a
 * convenience, because the input is a file fetched over the network. Four
 * things are checked, and each is checked because the failure without it is
 * silent:
 *
 *  1. **A path that escapes the destination.** An entry named
 *     `../../.ssh/authorized_keys`, or an absolute path, writes outside the
 *     directory the operator chose. The archive is rejected *whole*, before
 *     anything is written, so a hostile archive cannot be half-extracted.
 *  2. **A link entry.** A symlink or a hardlink is a pointer to somewhere
 *     else; extracting one is how an archive writes outside its destination
 *     without a single `..` appearing in any name.
 *  3. **A nested archive.** `phar://` will happily open a `.tar` sitting
 *     inside a `.zip`, which is a second parsing layer nobody inspected and a
 *     known deserialisation surface. The downloader needs exactly one archive,
 *     so a second one is refused by extension.
 *  4. **An implausible entry count or total size.** Not a defence on its own,
 *     but a zip bomb is cheap to refuse once the entries are being seen.
 *
 * **The names are read faithfully, and that is the load-bearing part.** An
 * earlier version of this class listed a tar through `PharData`, which
 * *normalises* `../evil.txt` into a directory named `..` and drops the payload
 * entirely - so the traversal check never saw a `..` component and passed while
 * defending nothing. `ZipArchive` reports the same name verbatim, which is how
 * the asymmetry was noticed. `TarReader` now reads tar headers directly, so
 * both formats hand this class the same faithful list and one check guards both.
 */
final class Archive
{
    public const MAX_ENTRIES = 1000;
    public const MAX_UNCOMPRESSED_BYTES = 256 * 1024 * 1024;

    /**
     * Extensions that would be a second archive inside the first.
     */
    private const NESTED = ['tar', 'tgz', 'gz', 'zip', 'phar', 'bz2', 'xz', '7z', 'rar'];

    /**
     * @param list<array{name:string,type:string,size:int,mode:int,index:int}> $entries
     */
    private function __construct(
        private readonly string $path,
        private readonly string $format,
        private readonly array $entries,
    ) {
    }

    /**
     * @throws Failure When the archive cannot be read or an entry is unsafe.
     */
    public static function open(string $path): self
    {
        if (!is_file($path)) {
            throw new Failure(sprintf('no archive at %s', $path));
        }

        // The format is decided by the first bytes, not by the filename: the
        // name came from a remote server and the magic number did not.
        $magic = (string) @file_get_contents($path, false, null, 0, 4);

        $archive = str_starts_with($magic, "PK")
            ? self::fromZip($path)
            : self::fromTar($path);

        $archive->assertSafeEntries();

        return $archive;
    }

    private static function fromZip(string $path): self
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new Failure('this PHP has no zip extension, so a .zip archive cannot be read');
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new Failure(sprintf('%s is not a readable zip', basename($path)));
        }

        $entries = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat) || !isset($stat['name']) || !is_string($stat['name'])) {
                $zip->close();

                throw new Failure(sprintf('%s has an entry with no name', basename($path)));
            }

            $entries[] = [
                'name' => $stat['name'],
                // `ZipArchive` reports a directory as a name ending in `/`;
                // there is no type field to read.
                'type' => str_ends_with($stat['name'], '/') ? 'dir' : 'file',
                'size' => (int) ($stat['size'] ?? 0),
                'mode' => 0o755,
                'index' => $i,
            ];
        }
        $zip->close();

        return new self($path, 'zip', $entries);
    }

    private static function fromTar(string $path): self
    {
        return new self($path, 'tar', TarReader::entries($path));
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_map(static fn (array $entry): string => $entry['name'], $this->entries);
    }

    /**
     * @return list<array{name:string,type:string,size:int,mode:int,index:int}>
     */
    public function entries(): array
    {
        return $this->entries;
    }

    public function format(): string
    {
        return $this->format;
    }

    /**
     * @throws Failure
     */
    private function assertSafeEntries(): void
    {
        if ($this->entries === []) {
            throw new Failure(sprintf('%s is empty', basename($this->path)));
        }

        if (count($this->entries) > self::MAX_ENTRIES) {
            throw new Failure(sprintf(
                '%s has more than %d entries',
                basename($this->path),
                self::MAX_ENTRIES,
            ));
        }

        $total = 0;
        foreach ($this->entries as $entry) {
            self::assertSafe($entry['name']);
            self::assertNotNested($entry['name']);

            if ($entry['type'] === 'symlink' || $entry['type'] === 'hardlink') {
                throw new Failure(sprintf(
                    '%s contains a link (%s), which could write outside the extraction directory',
                    basename($this->path),
                    $entry['name'],
                ));
            }

            if ($entry['type'] !== 'file') {
                continue;
            }

            $total += $entry['size'];
            if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                throw new Failure(sprintf(
                    '%s expands to more than %d MiB',
                    basename($this->path),
                    intdiv(self::MAX_UNCOMPRESSED_BYTES, 1024 * 1024),
                ));
            }
        }
    }

    /**
     * One entry name, judged on its own.
     *
     * Public so the rule can be asserted directly on names that are otherwise
     * awkward to build an archive around, which is the difference between
     * testing the rule and testing the harness.
     *
     * @throws Failure
     */
    public static function assertSafe(string $name): void
    {
        if ($name === '' || str_contains($name, "\0")) {
            throw new Failure('the archive contains an entry with an empty or NUL name');
        }

        // A backslash is a separator on Windows and a legal filename character
        // on Unix. Treating it as a separator on both is the safe reading: it
        // can only make a name that is already suspicious be rejected, and it
        // closes `..\\..\\evil` on the platform this tool also ships on.
        if (str_starts_with($name, '/')
            || str_starts_with($name, '\\')
            || preg_match('#^[A-Za-z]:#', $name) === 1) {
            throw new Failure(sprintf('the archive contains an absolute path: %s', $name));
        }

        foreach (preg_split('#[/\\\\]#', $name) ?: [] as $component) {
            if ($component === '..') {
                throw new Failure(sprintf('the archive contains a path that escapes it: %s', $name));
            }
        }
    }

    /**
     * @throws Failure
     */
    private static function assertNotNested(string $name): void
    {
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if (in_array($extension, self::NESTED, true)) {
            throw new Failure(sprintf(
                'the archive contains a second archive (%s), which is not extracted',
                $name,
            ));
        }
    }

    /**
     * Where an entry lands inside `$destination`, after its name is judged safe.
     */
    public static function targetPath(string $destination, string $name): string
    {
        return $destination . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $name);
    }

    /**
     * Create an entry's parent directory, and check that it is still inside
     * `$destination` once resolved.
     *
     * Belt-and-braces after `assertSafe`, because `realpath` is what accounts
     * for a path component that is itself a link - and on Windows a junction is
     * a link that no name check can see.
     *
     * @throws Failure
     */
    public static function prepareParent(string $destination, string $target, string $name): void
    {
        $parent = dirname($target);

        if (!is_dir($parent) && !@mkdir($parent, 0o755, true) && !is_dir($parent)) {
            throw new Failure(sprintf('could not create %s', $parent));
        }

        $root = realpath($destination);
        $resolved = realpath($parent);

        if ($root !== false && $resolved !== false && $resolved !== $root
            && !str_starts_with($resolved . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR)) {
            throw new Failure(sprintf('%s would extract outside %s', $name, $destination));
        }
    }

    /**
     * Extract into `$destination`.
     *
     * @throws Failure
     */
    public function extractTo(string $destination): void
    {
        if ($this->format === 'tar') {
            TarReader::extractTo($this->path, $destination, $this->entries);

            return;
        }

        foreach ($this->entries as $entry) {
            if ($entry['type'] !== 'file') {
                continue;
            }

            $target = self::targetPath($destination, $entry['name']);
            self::prepareParent($destination, $target, $entry['name']);
            $this->extractZipEntry($entry, $target);
        }
    }

    /**
     * @param array{name:string,type:string,size:int,mode:int,index:int} $entry
     */
    private function extractZipEntry(array $entry, string $target): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($this->path) !== true) {
            throw new Failure(sprintf('%s is no longer readable', basename($this->path)));
        }

        $stream = $zip->getStream($entry['name']);
        if ($stream === false) {
            $zip->close();

            throw new Failure(sprintf('could not read %s from the archive', $entry['name']));
        }

        $handle = @fopen($target, 'wb');
        if ($handle === false) {
            fclose($stream);
            $zip->close();

            throw new Failure(sprintf('could not write %s', $target));
        }

        stream_copy_to_stream($stream, $handle);
        fclose($handle);
        fclose($stream);
        $zip->close();

        @chmod($target, 0o755);
    }
}
