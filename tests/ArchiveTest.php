<?php

declare(strict_types=1);

/**
 * Extraction, and the refusals that make it a security boundary.
 *
 * The two formats are tested against the *same* hostile names, because the
 * defect that shaped this class was an asymmetry between them: `PharData`
 * normalises a stored `../evil.txt` into a directory named `..` and reports no
 * payload, so a tar could carry the traversal while a zip could not, and a
 * check written against `PharData`'s listing never saw it. Every hostile case
 * below is asserted for a tar *and* a zip.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';
require __DIR__ . '/FixtureArchive.php';

use Volucer\Cli\Archive;
use Volucer\Cli\Failure;

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volucer-archive-test-' . getmypid();

function scratch(string $root, string $name): string
{
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($path)) {
        mkdir($path, 0o755, true);
    }

    return $path;
}

$prefix = 'volucer-0.1.0-x86_64-unknown-linux-gnu';

// --- 1. A correct archive extracts, and the payload survives the round trip.

$good = FixtureArchive::write(
    FixtureArchive::tarGz(
        // The mode is explicit, and it matters: a real release archive is built
        // with `cp` from `target/release`, so the binary inside carries 0755.
        // The first version of this test left the mode at its default of 0644
        // and then asserted the extracted file was executable, which failed on
        // Linux and macOS and passed on Windows - where there is no executable
        // bit to check. A fixture that does not carry the property the code
        // relies on cannot test the code.
        FixtureArchive::tarEntry($prefix . '/volucer', "#!/bin/sh\necho volucer\n", '0', 0o755),
        FixtureArchive::tarEntry($prefix . '/README.md', 'the readme'),
    ),
    'tar.gz',
);

$archive = Archive::open($good);
Assert::same('tar', $archive->format(), 'a gzip stream is read as a tar');
Assert::same(2, count($archive->entries()), 'both entries are seen');
Assert::same(
    [$prefix . '/volucer', $prefix . '/README.md'],
    $archive->names(),
    'names are read exactly, in order',
);

$out = scratch($tmpRoot, 'good');
$archive->extractTo($out);
Assert::same(
    "#!/bin/sh\necho volucer\n",
    file_get_contents($out . DIRECTORY_SEPARATOR . $prefix . DIRECTORY_SEPARATOR . 'volucer'),
    'the binary content round-trips',
);
Assert::same(
    'the readme',
    file_get_contents($out . DIRECTORY_SEPARATOR . $prefix . DIRECTORY_SEPARATOR . 'README.md'),
    'the second entry round-trips',
);

// The executable bit is carried, so a downloaded binary runs without a second
// step. Unix only: Windows has no such bit to read back.
if (DIRECTORY_SEPARATOR === '/') {
    Assert::true(
        is_executable($out . DIRECTORY_SEPARATOR . $prefix . DIRECTORY_SEPARATOR . 'volucer'),
        'the archive\'s mode makes the binary executable',
    );
    // And it is per-entry rather than a blanket 0755: a document must not come
    // out executable, which a blanket chmod would do unnoticed.
    Assert::true(
        !is_executable($out . DIRECTORY_SEPARATOR . $prefix . DIRECTORY_SEPARATOR . 'README.md'),
        'a non-executable entry does not come out executable',
    );
}

// --- 2. Traversal. The same name, in both formats. ------------------------

$traversal = '../evil.txt';

$tar = FixtureArchive::write(
    FixtureArchive::tarGz(
        FixtureArchive::tarEntry($prefix . '/volucer', 'ok'),
        FixtureArchive::tarEntry($traversal, 'pwned'),
    ),
    'tar.gz',
);
Assert::throws(
    fn () => Archive::open($tar),
    'a tar carrying ../ is refused',
    'escapes it',
);

$zip = FixtureArchive::hostileZip($traversal, 'pwned');
Assert::throws(
    fn () => Archive::open($zip),
    'a zip carrying ../ is refused',
    'escapes it',
);

// The reason both refusals are load-bearing: the reader must *see* the name.
// If `TarReader` normalised the way `PharData` does, `open` would succeed and
// `../evil.txt` would be absent from this list.
$seen = null;
try {
    $entries = Volucer\Cli\TarReader::entries($tar);
    $seen = array_column($entries, 'name');
} catch (Throwable $e) {
    $seen = ['<threw>'];
}
Assert::true(
    is_array($seen) && in_array($traversal, $seen, true),
    'the tar reader reports the stored name faithfully, not a normalised one',
);

// --- 3. Absolute paths, in the three shapes. -----------------------------

foreach (['/etc/cron.d/evil', 'C:\\Windows\\evil.dll', '\\\\server\\share\\evil'] as $absolute) {
    Assert::throws(
        fn () => Archive::open(FixtureArchive::hostileZip($absolute, 'x')),
        "an absolute zip entry ({$absolute}) is refused",
    );
    Assert::throws(
        fn () => Archive::open(FixtureArchive::write(
            FixtureArchive::tarGz(FixtureArchive::tarEntry($absolute, 'x')),
            'tar.gz',
        )),
        "an absolute tar entry ({$absolute}) is refused",
    );
}

// A backslash-separated traversal, which is only a traversal on Windows and so
// is exactly the case a Unix-only check would miss.
Assert::throws(
    fn () => Archive::assertSafe('..\\..\\evil'),
    'a backslash traversal is refused',
    'escapes it',
);

// --- 4. Links. A link needs no `..` to write outside the destination. ------

$symlink = FixtureArchive::write(
    FixtureArchive::tarGz(
        FixtureArchive::tarEntry($prefix . '/volucer', 'ok'),
        FixtureArchive::tarEntry($prefix . '/passwd', '/etc/passwd', '2'),
    ),
    'tar.gz',
);
Assert::throws(
    fn () => Archive::open($symlink),
    'a symlink entry is refused',
    'contains a link',
);

$hardlink = FixtureArchive::write(
    FixtureArchive::tarGz(
        FixtureArchive::tarEntry($prefix . '/volucer', 'ok'),
        FixtureArchive::tarEntry($prefix . '/passwd', '/etc/passwd', '1'),
    ),
    'tar.gz',
);
Assert::throws(
    fn () => Archive::open($hardlink),
    'a hardlink entry is refused',
    'contains a link',
);

// --- 5. A second archive inside the first. -------------------------------

Assert::throws(
    fn () => Archive::open(FixtureArchive::hostileZip($prefix . '/inner.tar', 'x')),
    'a nested tar inside a zip is refused',
    'second archive',
);
Assert::throws(
    fn () => Archive::open(FixtureArchive::write(
        FixtureArchive::tarGz(
            FixtureArchive::tarEntry($prefix . '/volucer', 'ok'),
            FixtureArchive::tarEntry($prefix . '/inner.zip', 'PK'),
        ),
        'tar.gz',
    )),
    'a nested zip inside a tar is refused',
    'second archive',
);

// --- 6. Empty and unreadable input. --------------------------------------

Assert::throws(fn () => Archive::open($tmpRoot . '/does-not-exist.tar.gz'), 'a missing file is refused');
Assert::throws(
    fn () => Archive::open(FixtureArchive::write(str_repeat("\0", 2048), 'tar')),
    'an archive with no entries is refused',
    'is empty',
);

// --- 7. The name rule, asserted directly. --------------------------------
// Built as a table because these names are awkward to get into an archive, and
// the rule is what matters rather than the harness.

$safe = [
    'volucer-0.1.0-x86_64-unknown-linux-gnu/volucer',
    'packaging/systemd/volucer.service',
    'README.md',
    './volucer',
    'a/./b',
    '..hidden',
    'a..b',
    'x/..y/z',
];
foreach ($safe as $name) {
    try {
        Archive::assertSafe($name);
        Assert::true(true, "safe: {$name}");
    } catch (Failure $e) {
        Assert::true(false, "safe: {$name} was wrongly refused ({$e->getMessage()})");
    }
}

$unsafe = ['..', '../x', 'a/../b', 'a/..', '..\\x', '/x', '\\x', 'C:x', ''];
foreach ($unsafe as $name) {
    Assert::throws(fn () => Archive::assertSafe($name), "unsafe: {$name}");
}

// A name containing a NUL cannot be written by most tools but can be stored.
Assert::throws(
    fn () => Archive::assertSafe("good\0../evil"),
    'a NUL in a name is refused',
    'NUL',
);

// --- 8. Extraction refuses a name that a later layer normalises. ---------
// `targetPath` must not silently collapse anything, so `..` can never reappear
// between the check and the write.

Assert::same(
    '/tmp/x' . DIRECTORY_SEPARATOR . 'packaging' . DIRECTORY_SEPARATOR . 'systemd',
    Archive::targetPath('/tmp/x', 'packaging/systemd'),
    'a nested path is joined with the host separator',
);
// The stored separator is always `/`, whichever host reads it, so a Windows
// machine must translate it rather than produce a name with a forward slash.
Assert::true(
    !str_contains(Archive::targetPath('/tmp/x', 'a/b'), '/b')
        || DIRECTORY_SEPARATOR === '/',
    'a stored forward slash becomes the host separator',
);

// --- cleanup -------------------------------------------------------------
$remove = static function (string $path) use (&$remove): void {
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                $remove($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        @rmdir($path);

        return;
    }
    @unlink($path);
};
$remove($tmpRoot);
foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixture-*') ?: [] as $left) {
    @unlink($left);
}

exit(Assert::report('ArchiveTest'));
