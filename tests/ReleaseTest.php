<?php

declare(strict_types=1);

/**
 * Version ordering.
 *
 * This decides which release a bare `volucer-cli` installs, so getting it wrong
 * installs the wrong thing rather than failing. The case that makes it worth
 * testing is the pre-release: `0.2.0-rc.1` is newer than `0.1.0` and older than
 * `0.2.0`, and a naive string comparison gets both of those wrong.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Cli\Release;

$ordered = ['v0.1.0', 'v0.1.1', 'v0.2.0-rc.1', 'v0.2.0', 'v0.10.0', 'v1.0.0'];

for ($i = 0; $i < count($ordered) - 1; $i++) {
    $before = $ordered[$i];
    $after = $ordered[$i + 1];

    Assert::true(Release::compare($before, $after) < 0, "{$before} < {$after}");
    Assert::true(Release::compare($after, $before) > 0, "{$after} > {$before}");
}

// Equal versions compare equal, with or without the tag's `v`.
Assert::same(0, Release::compare('v0.1.0', '0.1.0'), 'the v prefix does not affect ordering');
Assert::same(0, Release::compare('v1.2.3', 'v1.2.3'), 'equal tags compare equal');

// The two cases a string comparison gets wrong, asserted by name so a
// regression names itself.
Assert::true(
    Release::compare('v0.2.0', 'v0.10.0') < 0,
    '0.2.0 < 0.10.0 (string comparison would say otherwise)',
);
Assert::true(
    Release::compare('v0.2.0-rc.1', 'v0.2.0') < 0,
    'a pre-release sorts below its release',
);
Assert::true(
    Release::compare('v0.2.0-rc.10', 'v0.2.0-rc.2') > 0,
    'rc.10 is newer than rc.2 (numeric, not lexical)',
);
Assert::true(
    Release::compare('v0.2.0-alpha', 'v0.2.0-beta') < 0,
    'pre-release names are ordered',
);

// A release's version is the tag without the v, which is what appears inside an
// asset name: `v0.1.0` carries `volucer-0.1.0-...`.
Assert::same('0.1.0', (new Release('v0.1.0', []))->version(), 'version() strips the tag prefix');
Assert::same('0.1.0', (new Release('0.1.0', []))->version(), 'version() is a no-op without a prefix');

// Sorting a shuffled list puts it in order, which is what `Releases::list` does.
$shuffled = ['v0.10.0', 'v0.2.0-rc.1', 'v0.1.0', 'v0.2.0'];
usort($shuffled, static fn (string $a, string $b): int => Release::compare($a, $b));
Assert::same(
    ['v0.1.0', 'v0.2.0-rc.1', 'v0.2.0', 'v0.10.0'],
    $shuffled,
    'a shuffled list sorts into release order',
);

// has() is exact rather than fuzzy: an asset the release does not carry must
// not be found by prefix.
$release = new Release('v0.1.0', ['volucer-0.1.0-x86_64-unknown-linux-gnu.tar.gz']);
Assert::true(
    $release->has('volucer-0.1.0-x86_64-unknown-linux-gnu.tar.gz'),
    'has() finds an exact asset',
);
Assert::true(
    !$release->has('volucer-0.1.0-x86_64-unknown-linux-gnu.tar.gz.sha256'),
    'has() does not find an asset by prefix',
);
Assert::true(
    !$release->has('volucer-0.1.0-x86_64-unknown-linux-musl.tar.gz'),
    'has() does not find a different target',
);

exit(Assert::report('ReleaseTest'));
