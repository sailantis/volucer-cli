<?php

declare(strict_types=1);

/**
 * Platform detection.
 *
 * Every case is a table entry rather than something read from this machine, so
 * the arm64 and Alpine paths are checked from Windows - which is the whole
 * reason `Platform` takes its inputs as arguments.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Cli\Failure;
use Volucer\Cli\Platform;

// PHP_OS_FAMILY is a closed set, so this is a total function.
Assert::same('windows', Platform::osFromFamily('Windows'), 'Windows -> windows');
Assert::same('darwin', Platform::osFromFamily('Darwin'), 'Darwin -> darwin');
Assert::same('linux', Platform::osFromFamily('Linux'), 'Linux -> linux');
Assert::same('bsd', Platform::osFromFamily('BSD'), 'BSD -> bsd');
Assert::throws(fn () => Platform::osFromFamily('Solaris'), 'an unknown family is refused', 'unsupported');
Assert::throws(fn () => Platform::osFromFamily(''), 'an empty family is refused');

// The four spellings of x86-64 that actually occur.
foreach (['x86_64', 'AMD64', 'x64', 'amd64', 'EM64T'] as $uname) {
    Assert::same('x86_64', Platform::archFromUname($uname), "uname '{$uname}' -> x86_64");
}
foreach (['aarch64', 'arm64', 'ARM64'] as $uname) {
    Assert::same('arm64', Platform::archFromUname($uname), "uname '{$uname}' -> arm64");
}

// `x86` is the *32-bit* architecture, and RoadRunner maps it onto amd64. Doing
// that would hand a 32-bit machine a 64-bit binary and a loader error that
// names neither. This asserts the deliberate divergence.
Assert::same('x86_32', Platform::archFromUname('x86'), "'x86' is 32-bit, not amd64");
Assert::same('x86_32', Platform::archFromUname('i686'), "'i686' is 32-bit");
Assert::same('arm_32', Platform::archFromUname('armv7l'), "'armv7l' is 32-bit");
Assert::same('riscv64', Platform::archFromUname('riscv64'), "'riscv64' is recognised");
Assert::throws(fn () => Platform::archFromUname('sparc'), 'an unknown architecture is refused', 'unsupported');

// Alpine detection: three signals, and the false positives that matter.
Assert::true(
    Platform::isAlpine('Linux', 'Linux', '3.19.1', false) === false,
    'a stock Linux with no signals is not Alpine',
);
Assert::true(
    Platform::isAlpine('Linux', 'Linux', '6.6.0', true),
    'the /etc/alpine-release file alone is enough',
);
Assert::true(
    Platform::isAlpine('Alpine Linux', 'Linux', '6.6.0', false),
    'the PHP_OS string alone is enough',
);
Assert::true(
    Platform::isAlpine('Linux', 'Linux', '6.6.0-Alpine', false),
    'the kernel version string alone is enough',
);
Assert::true(
    Platform::isAlpine('Linux', 'Darwin', '6.6.0', true) === false,
    'Alpine is only a Linux question, so macOS with the file is still false',
);

// `of` normalises: Alpine on macOS or Windows is not a thing.
$mac = Platform::of('darwin', 'arm64', true);
Assert::same(false, $mac->alpine, 'a non-Linux platform is never Alpine');
Assert::same('darwin/arm64', $mac->describe(), 'describe() names the platform');
Assert::same('linux/x86_64, musl', Platform::of('linux', 'x86_64', true)->describe(), 'musl is named');
Assert::same('linux/x86_64, glibc', Platform::of('linux', 'x86_64')->describe(), 'glibc is named');
Assert::same('windows/x86_64', Platform::of('windows', 'x86_64')->describe(), 'no libc on Windows');

Assert::throws(fn () => Platform::of('plan9', 'x86_64'), 'an unknown OS in of() is refused');
Assert::throws(fn () => Platform::of('linux', 'itanium'), 'an unknown arch in of() is refused');

// The real machine still works, whatever it is.
$real = Platform::createFromGlobals();
Assert::true(in_array($real->os, ['windows', 'linux', 'darwin', 'bsd'], true), 'this host resolves');
Assert::true($real->arch !== '', 'this host has an architecture');

exit(Assert::report('PlatformTest'));
