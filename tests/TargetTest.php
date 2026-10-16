<?php

declare(strict_types=1);

/**
 * Target selection, and the refusals.
 *
 * The six asset names asserted here are the six archives the server's release
 * workflow builds. If that matrix changes, this file fails - which is the point:
 * a downloader that silently stops matching the releases it is meant to install
 * is worse than one that does not build.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Cli\Failure;
use Volucer\Cli\Platform;
use Volucer\Cli\Target;

$cases = [
    ['linux', 'x86_64', false, 'x86_64-unknown-linux-gnu', 'tar.gz'],
    ['linux', 'x86_64', true, 'x86_64-unknown-linux-musl', 'tar.gz'],
    ['linux', 'arm64', false, 'aarch64-unknown-linux-gnu', 'tar.gz'],
    ['darwin', 'x86_64', false, 'x86_64-apple-darwin', 'tar.gz'],
    ['darwin', 'arm64', false, 'aarch64-apple-darwin', 'tar.gz'],
    ['windows', 'x86_64', false, 'x86_64-pc-windows-msvc', 'zip'],
];

foreach ($cases as [$os, $arch, $alpine, $triple, $extension]) {
    $target = Target::for(Platform::of($os, $arch, $alpine));
    $label = sprintf('%s/%s%s', $os, $arch, $alpine ? '+musl' : '');

    Assert::same($triple, $target->triple, "{$label} -> {$triple}");
    Assert::same($extension, $target->extension, "{$label} -> .{$extension}");
    Assert::same(
        sprintf('volucer-0.1.0-%s.%s', $triple, $extension),
        $target->assetName('0.1.0'),
        "{$label} asset name",
    );
    Assert::same(
        sprintf('volucer-0.1.0-%s', $triple),
        $target->directoryName('0.1.0'),
        "{$label} directory name",
    );
}

// The extension and the binary name travel together: a zip means Windows.
Assert::same(
    'volucer.exe',
    Target::for(Platform::of('windows', 'x86_64'))->binaryName(),
    'the Windows archive carries volucer.exe',
);
Assert::same(
    'volucer',
    Target::for(Platform::of('linux', 'x86_64'))->binaryName(),
    'a Unix archive carries volucer',
);

// The glibc and musl names must actually differ, or the whole Alpine branch is
// decoration. This is the assertion that would fail if the flag were ignored.
Assert::true(
    Target::for(Platform::of('linux', 'x86_64', true))->assetName('0.1.0')
        !== Target::for(Platform::of('linux', 'x86_64', false))->assetName('0.1.0'),
    'the musl and glibc archives are different files',
);

// --- Refusals. Each names the thing it cannot do. -------------------------

Assert::throws(
    fn () => Target::for(Platform::of('linux', 'arm64', true)),
    'Alpine on arm64 is refused',
    'no musl archive for Linux on arm64',
);
Assert::throws(
    fn () => Target::for(Platform::of('bsd', 'x86_64')),
    'BSD is refused',
    'no archive for BSD',
);
Assert::throws(
    fn () => Target::for(Platform::of('linux', 'x86_32')),
    '32-bit Linux is refused',
    'no archive for Linux on x86_32',
);
Assert::throws(
    fn () => Target::for(Platform::of('linux', 'arm_32')),
    '32-bit ARM Linux is refused',
    'no archive for Linux on arm_32',
);
Assert::throws(
    fn () => Target::for(Platform::of('darwin', 'x86_32')),
    '32-bit macOS is refused',
    'no archive for macOS on x86_32',
);
Assert::throws(
    fn () => Target::for(Platform::of('windows', 'arm64')),
    'ARM Windows is refused',
    'no archive for Windows on arm64',
);

// The refusal for Alpine/arm64 must name what *is* available, because an
// operator who has just been told "no" needs to know whether they are stuck.
$message = '';
try {
    Target::for(Platform::of('linux', 'arm64', true));
} catch (Failure $e) {
    $message = $e->getMessage();
}
Assert::contains('x86_64 musl', $message, 'the refusal lists the musl build that exists');
Assert::contains('arm64 glibc', $message, 'the refusal lists the arm64 build that exists');

// Every supported combination resolves, which is the property the list of
// refusals is easy to get wrong about. Alpine/arm64 is deliberately absent: it
// is one of the refusals, and listing it here is how the first version of this
// test failed.
$supported = [
    ['linux', 'x86_64', false],
    ['linux', 'x86_64', true],
    ['linux', 'arm64', false],
    ['darwin', 'x86_64', false],
    ['darwin', 'arm64', false],
    ['windows', 'x86_64', false],
];
foreach ($supported as [$os, $arch, $alpine]) {
    $target = Target::for(Platform::of($os, $arch, $alpine));
    Assert::true($target->triple !== '', sprintf('%s/%s%s resolves', $os, $arch, $alpine ? '+musl' : ''));
    Assert::true(
        $target->binaryName() !== '',
        sprintf('%s/%s%s has a binary name', $os, $arch, $alpine ? '+musl' : ''),
    );
}

exit(Assert::report('TargetTest'));
