<?php

declare(strict_types=1);

/**
 * The checksum, which is the only thing standing between a hostile or corrupt
 * download and an operator's filesystem.
 *
 * The format cases matter because the file is produced by `sha256sum` on the
 * server and read here; a format disagreement would look like a corrupt
 * download and send someone hunting for a network problem.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Cli\Checksum;
use Volucer\Cli\Failure;

$hex = str_repeat('a1b2c3d4', 8); // 64 characters
Assert::same(64, strlen($hex), 'the fixture digest is the right length');

// --- The format `sha256sum` writes, which is what the release produces. ---
$sumsStyle = $hex . "  volucer-0.1.0-x86_64-unknown-linux-gnu.tar.gz\n";
$parsed = Checksum::parse($sumsStyle, 'test');
Assert::same('sha256', $parsed->algorithm, 'sha256sum output names sha256');
Assert::same($hex, $parsed->hex, 'sha256sum output parses the digest');

// The bare-digest form, and the single-space form.
Assert::same($hex, Checksum::parse($hex . "\n", 'test')->hex, 'a bare digest parses');
Assert::same($hex, Checksum::parse($hex . '  file.tar.gz', 'test')->hex, 'no trailing newline still parses');
Assert::same($hex, Checksum::parse($hex . ' *file.tar.gz', 'test')->hex, 'the binary-mode asterisk parses');

// Uppercase hex is normalised, because a digest is compared as a number.
Assert::same(
    $hex,
    Checksum::parse(strtoupper($hex), 'test')->hex,
    'uppercase hex is normalised',
);

// Comments and blank lines are skipped rather than parsed as digests.
Assert::same(
    $hex,
    Checksum::parse("# created by build\n\n" . $sumsStyle, 'test')->hex,
    'comment and blank lines are skipped',
);

// The BSD form names its algorithm and its file.
$bsd = Checksum::parse('SHA256 (volucer.tar.gz) = ' . $hex, 'test');
Assert::same('sha256', $bsd->algorithm, 'the BSD form names sha256');
Assert::same($hex, $bsd->hex, 'the BSD form parses the digest');

// --- Refusals. -----------------------------------------------------------

Assert::throws(fn () => Checksum::parse('', 'empty.sha256'), 'an empty checksum file is refused', 'is empty');
Assert::throws(fn () => Checksum::parse("   \n\n", 'blank.sha256'), 'a whitespace-only file is refused');
Assert::throws(
    fn () => Checksum::parse("not a checksum at all\n", 'bad.sha256'),
    'a line that is not a digest is refused',
    'does not look like a checksum',
);

// A short digest is refused rather than compared, because a truncated checksum
// would compare unequal and be reported as a corrupt download.
Assert::throws(
    fn () => Checksum::parse("abc123  file.tar.gz\n", 'short.sha256'),
    'a too-short digest is refused',
);

// --- Verification, against a real file. ----------------------------------

$file = tempnam(sys_get_temp_dir(), 'checksum-');
file_put_contents($file, "the bytes\n");
$real = hash_file('sha256', $file);

Assert::true(
    Checksum::parse($real . '  file', 'inline')->verifyFile($file),
    'a matching digest verifies a file',
);
Assert::true(
    !Checksum::parse(str_repeat('0', 64) . '  file', 'inline')->verifyFile($file),
    'a non-matching digest does not verify',
);
Assert::same($real, Checksum::ofFile($file), 'ofFile() hashes the same way');

// A digest that differs in one character must fail. This is the assertion a
// prefix comparison would pass.
$almost = substr($real, 0, -1) . ($real[-1] === '0' ? '1' : '0');
Assert::true(
    !Checksum::parse($almost . '  file', 'inline')->verifyFile($file),
    'a digest differing in one character fails',
);

unlink($file);

exit(Assert::report('ChecksumTest'));
