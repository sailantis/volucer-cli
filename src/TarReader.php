<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * Reading a tar archive by its headers.
 *
 * **Why this exists instead of `PharData`.** `PharData` is the obvious choice
 * and it is the wrong one, for a reason that only shows up when it is tested:
 * it *normalises* a path as it reads it. An entry whose stored name is
 * `../evil.txt` is reported by `PharData` as a **directory named `..`**, and its
 * payload as no leaf at all - so a traversal check written against `PharData`'s
 * own listing never sees a `..` component to refuse, and passes while defending
 * nothing.
 *
 * `ZipArchive::statIndex`, by contrast, reports `../evil.txt` verbatim. That
 * asymmetry is the whole reason this class exists: tar names are read here from
 * the raw 512-byte headers, so both formats hand `Archive` the same faithful
 * list, and one check guards both.
 *
 * This is a *reader*, not a general tar implementation. It refuses what it does
 * not understand rather than guessing, because a pax header it skipped could be
 * the one carrying the real name.
 */
final class TarReader
{
    private const BLOCK = 512;

    /** Tar header field offsets. */
    private const OFF_NAME = 0;
    private const OFF_SIZE = 124;
    private const OFF_TYPE = 156;
    private const OFF_PREFIX = 345;

    private function __construct()
    {
    }

    /**
     * The entries in a tar or tar.gz, in file order.
     *
     * @return list<array{name:string,type:string,size:int,mode:int,index:int}>
     *
     * @throws Failure
     */
    public static function entries(string $path): array
    {
        [$read, $close] = self::stream($path);

        try {
            $entries = [];
            $longName = null;
            $index = 0;

            while (true) {
                $header = $read(self::BLOCK);

                // Two ways to end: the run of zero blocks that terminates a
                // tar, and a short read at EOF. Both are normal.
                if (strlen($header) < self::BLOCK) {
                    break;
                }
                if (rtrim($header, "\0") === '') {
                    break;
                }

                $size = self::size($header);
                $type = $header[self::OFF_TYPE] === "\0" ? '0' : $header[self::OFF_TYPE];

                // GNU long name: the payload *is* the next entry's name, and
                // the entry itself carries no data.
                if ($type === 'L') {
                    $longName = rtrim($read($size), "\0");
                    $read(self::padding($size));
                    continue;
                }

                if ($type === 'x' || $type === 'g') {
                    // A pax extended header may override the name, the size or
                    // the type of the entry that follows. Ignoring it means
                    // validating a name that is not the one which will be used,
                    // so it is refused outright.
                    throw new Failure(sprintf(
                        '%s uses pax extended headers, which this tool does not read',
                        basename($path),
                    ));
                }

                $name = $longName ?? self::name($header);
                $longName = null;

                $entries[] = [
                    'name' => $name,
                    'type' => self::entryType($type),
                    'size' => $size,
                    'mode' => self::mode($header),
                    'index' => $index,
                ];
                $index++;

                // The data blocks follow, padded to a block boundary. They are
                // read and discarded: this pass collects headers only, so the
                // archive is never held in memory.
                $read($size);
                $read(self::padding($size));

                if (count($entries) > Archive::MAX_ENTRIES) {
                    throw new Failure(sprintf(
                        '%s has more than %d entries',
                        basename($path),
                        Archive::MAX_ENTRIES,
                    ));
                }
            }
        } finally {
            $close();
        }

        return $entries;
    }

    /**
     * Extract the named entries into `$destination`, in one forward pass.
     *
     * Names are re-validated here rather than trusted from the first pass: this
     * method is the thing that writes, so this is the last place a mistake can
     * be caught.
     *
     * @param list<array{name:string,type:string,size:int,mode:int,index:int}> $wanted
     *
     * @throws Failure
     */
    public static function extractTo(string $path, string $destination, array $wanted): void
    {
        $byName = [];
        foreach ($wanted as $entry) {
            $byName[$entry['name']] = $entry;
        }

        [$read, $close] = self::stream($path);

        try {
            $longName = null;

            while (true) {
                $header = $read(self::BLOCK);
                if (strlen($header) < self::BLOCK || rtrim($header, "\0") === '') {
                    break;
                }

                $size = self::size($header);
                $type = $header[self::OFF_TYPE] === "\0" ? '0' : $header[self::OFF_TYPE];

                if ($type === 'L') {
                    $longName = rtrim($read($size), "\0");
                    $read(self::padding($size));
                    continue;
                }

                $name = $longName ?? self::name($header);
                $longName = null;

                if (!isset($byName[$name]) || self::entryType($type) !== 'file') {
                    $read($size);
                    $read(self::padding($size));
                    continue;
                }

                Archive::assertSafe($name);
                $target = Archive::targetPath($destination, $name);
                Archive::prepareParent($destination, $target, $name);

                $handle = @fopen($target, 'wb');
                if ($handle === false) {
                    throw new Failure(sprintf('could not write %s', $target));
                }

                $remaining = $size;
                while ($remaining > 0) {
                    $chunk = $read(min($remaining, 65536));
                    if ($chunk === '') {
                        fclose($handle);

                        throw new Failure(sprintf('%s is truncated', basename($path)));
                    }
                    fwrite($handle, $chunk);
                    $remaining -= strlen($chunk);
                }
                fclose($handle);

                @chmod($target, $byName[$name]['mode'] & 0o777);

                $read(self::padding($size));
            }
        } finally {
            $close();
        }
    }

    /**
     * A reader over a tar or a gzipped tar, chosen by the gzip magic rather
     * than by the file extension - a name is a claim, the bytes are not.
     *
     * @return array{0: callable(int):string, 1: callable():void}
     */
    private static function stream(string $path): array
    {
        $magic = @file_get_contents($path, false, null, 0, 2);
        $gzipped = $magic === "\x1f\x8b";

        $handle = $gzipped ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if ($handle === false) {
            throw new Failure(sprintf('could not open %s', $path));
        }

        // `gzread` and `fread` are separate functions over separate handle
        // types, so the choice is made once here and the caller sees one shape.
        $read = $gzipped
            ? static function (int $bytes) use ($handle): string {
                $out = '';
                while (strlen($out) < $bytes) {
                    $chunk = gzread($handle, $bytes - strlen($out));
                    if (!is_string($chunk) || $chunk === '') {
                        break;
                    }
                    $out .= $chunk;
                }

                return $out;
            }
            : static function (int $bytes) use ($handle): string {
                $out = '';
                while (strlen($out) < $bytes) {
                    $chunk = fread($handle, $bytes - strlen($out));
                    if (!is_string($chunk) || $chunk === '') {
                        break;
                    }
                    $out .= $chunk;
                }

                return $out;
            };

        $close = $gzipped
            ? static function () use ($handle): void {
                gzclose($handle);
            }
            : static function () use ($handle): void {
                fclose($handle);
            };

        return [$read, $close];
    }

    private static function name(string $header): string
    {
        $name = rtrim(substr($header, self::OFF_NAME, 100), "\0");
        $prefix = rtrim(substr($header, self::OFF_PREFIX, 155), "\0");

        // ustar splits a long name into a prefix and a remainder.
        return $prefix === '' ? $name : $prefix . '/' . $name;
    }

    private static function size(string $header): int
    {
        $field = substr($header, self::OFF_SIZE, 12);

        // Sizes above 8 GiB are encoded as base-256 with the high bit set.
        // Reading that as octal would produce a nonsense length, so it is
        // refused rather than misparsed; no volucer archive is anywhere near it.
        if ($field !== '' && (ord($field[0]) & 0x80) !== 0) {
            throw new Failure('the archive uses base-256 size fields, which this tool does not read');
        }

        $text = trim($field, " \0");
        if ($text === '' || preg_match('/^[0-7]+$/', $text) !== 1) {
            throw new Failure('the archive has a header with a malformed size field');
        }

        return (int) octdec($text);
    }

    private static function mode(string $header): int
    {
        $text = trim(substr($header, 100, 8), " \0");

        return preg_match('/^[0-7]+$/', $text) === 1 ? (int) octdec($text) : 0o644;
    }

    private static function entryType(string $type): string
    {
        return match ($type) {
            '0', '7' => 'file',
            '5' => 'dir',
            // A link is not a file whose name can be checked; it is a pointer
            // to somewhere else, and extracting one is how an archive writes
            // outside its destination without a single `..` in a name.
            '1' => 'hardlink',
            '2' => 'symlink',
            default => 'other',
        };
    }

    private static function padding(int $size): int
    {
        return (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;
    }
}
