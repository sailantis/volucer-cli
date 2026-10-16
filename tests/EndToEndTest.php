<?php

declare(strict_types=1);

/**
 * The whole command, against a local server serving a fixture release.
 *
 * This is the test that makes the tool verified rather than described. There are
 * no releases on GitHub yet, so a live download has nothing to fetch - but the
 * claim "the downloader works" is separable from "GitHub serves the release",
 * and only the second needs a tag. With the API base URL pointed at
 * `php -S`, real bytes travel over a real socket: a real tar.gz and a real zip,
 * real SHA-256 sums, and the real extraction code.
 *
 * A fixture server is not a stub. Every class under test is the shipping one;
 * only the origin of the bytes is local.
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';
require __DIR__ . '/FixtureArchive.php';

use Volucer\Cli\Cli;
use Volucer\Cli\Output;

$version = '0.1.0';
$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volucer-cli-e2e-' . getmypid();

function e2eScratch(string $root, string $name): string
{
    $path = $root . DIRECTORY_SEPARATOR . $name;
    if (!is_dir($path)) {
        mkdir($path, 0o755, true);
    }

    return $path;
}

function e2eRemove(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                e2eRemove($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        @rmdir($path);

        return;
    }
    @unlink($path);
}

/**
 * Wait for the fixture server to answer, rather than sleeping a fixed amount.
 */
function e2eAwait(string $base, int $seconds = 10): bool
{
    $deadline = microtime(true) + $seconds;
    while (microtime(true) < $deadline) {
        $socket = @fsockopen('127.0.0.1', (int) parse_url($base, PHP_URL_PORT), $errno, $errstr, 0.5);
        if (is_resource($socket)) {
            fclose($socket);

            return true;
        }
        usleep(100000);
    }

    return false;
}

// --- Build the fixture release on disk. ----------------------------------

$public = e2eScratch($root, 'public');
$assets = $public . DIRECTORY_SEPARATOR . 'assets';
mkdir($assets, 0o755, true);

$prefix = 'volucer-' . $version . '-x86_64-unknown-linux-gnu';
$payload = "#!/bin/sh\necho 'volucer fixture'\n";

// The tar.gz that a Linux host installs.
$tarGzPath = $assets . DIRECTORY_SEPARATOR . $prefix . '.tar.gz';
file_put_contents($tarGzPath, FixtureArchive::tarGz(
    FixtureArchive::tarEntry($prefix . '/volucer', $payload, '0', 0o755),
    FixtureArchive::tarEntry($prefix . '/README.md', 'volucer readme'),
    FixtureArchive::tarEntry($prefix . '/packaging/systemd/volucer.service', '[Unit]'),
));

// The zip that a Windows host installs, in the same release.
$windowsPrefix = 'volucer-' . $version . '-x86_64-pc-windows-msvc';
$zipPath = $assets . DIRECTORY_SEPARATOR . $windowsPrefix . '.zip';
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$zip->addFromString($windowsPrefix . '/volucer.exe', 'MZ fixture');
$zip->addFromString($windowsPrefix . '/README.md', 'volucer readme');
$zip->close();

// The checksums, written the way `sha256sum` writes them.
file_put_contents(
    $tarGzPath . '.sha256',
    hash_file('sha256', $tarGzPath) . '  ' . basename($tarGzPath) . "\n",
);
file_put_contents(
    $zipPath . '.sha256',
    hash_file('sha256', $zipPath) . '  ' . basename($zipPath) . "\n",
);

// A router, because `php -S` needs one to answer API paths from a static tree.
$router = $public . DIRECTORY_SEPARATOR . 'router.php';
file_put_contents($router, <<<'PHP'
    <?php
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = __DIR__ . '/assets';

    // The releases list, with exactly the asset names that are on disk. Built
    // here rather than hardcoded, so the fixture cannot claim an asset it does
    // not have - which is the failure mode of a hand-written stub.
    if (preg_match('#^/repos/[^/]+/[^/]+/releases$#', $uri) === 1) {
        $assets = [];
        foreach (glob($base . '/*') ?: [] as $file) {
            $assets[] = ['name' => basename($file)];
        }
        header('Content-Type: application/json');
        echo json_encode([['tag_name' => 'v0.1.0', 'assets' => $assets]]);
        return true;
    }

    // A single release by tag.
    if (preg_match('#^/repos/[^/]+/[^/]+/releases/tags/(.+)$#', $uri, $m) === 1) {
        if ($m[1] !== 'v0.1.0') {
            http_response_code(404);
            echo '{"message":"Not Found"}';
            return true;
        }
        $assets = [];
        foreach (glob($base . '/*') ?: [] as $file) {
            $assets[] = ['name' => basename($file)];
        }
        header('Content-Type: application/json');
        echo json_encode(['tag_name' => 'v0.1.0', 'assets' => $assets]);
        return true;
    }

    // An asset download.
    if (preg_match('#^/repos/[^/]+/[^/]+/releases/download/[^/]+/(.+)$#', $uri, $m) === 1) {
        $file = $base . '/' . basename($m[1]);
        if (!is_file($file)) {
            http_response_code(404);
            echo '{"message":"Not Found"}';
            return true;
        }
        header('Content-Type: application/octet-stream');
        readfile($file);
        return true;
    }

    http_response_code(404);
    echo '{"message":"Not Found"}';

    return true;
    PHP);

$port = 8099;
$base = 'http://127.0.0.1:' . $port;
$descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $router],
    $descriptors,
    $pipes,
    $public,
);

if (!is_resource($server)) {
    fwrite(STDERR, "could not start the fixture server\n");
    exit(1);
}

// Both pipes must be drained or the server blocks once a buffer fills.
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

if (!e2eAwait($base)) {
    proc_terminate($server);
    fwrite(STDERR, "the fixture server never listened on {$port}\n");
    exit(1);
}

// --- Point the tool at it. -----------------------------------------------

putenv('VOLUCER_CLI_API=' . $base);

/**
 * Run the real entry point, capturing everything it prints.
 *
 * @param list<string> $argv
 *
 * @return array{0:int,1:string}
 */
function runCli(array $argv): array
{
    $stream = fopen('php://memory', 'w+b');
    $out = new Output($stream, $stream);
    $status = Cli::main(array_merge(['volucer-cli'], $argv), $out);
    rewind($stream);
    $printed = stream_get_contents($stream) ?: '';
    fclose($stream);

    return [$status, $printed];
}

// --- 1. A dry run resolves and installs nothing. ------------------------

$destination = e2eScratch($root, 'dry');
[$status, $printed] = runCli([
    '--dry-run',
    '--os', 'linux', '--arch', 'x86_64',
    '--location', $destination,
]);

Assert::same(0, $status, 'a dry run exits 0');
Assert::contains('checksum ok', str_replace('checksum sha256', 'checksum ok', $printed), 'the dry run reports the checksum');
Assert::contains('dry run: nothing was downloaded', $printed, 'the dry run says it downloaded nothing');
Assert::true(
    !is_file($destination . DIRECTORY_SEPARATOR . 'volucer'),
    'the dry run really did not install anything',
);

// --- 2. A real install, end to end. --------------------------------------

[$status, $printed] = runCli([
    '--os', 'linux', '--arch', 'x86_64',
    '--location', $destination,
]);

Assert::same(0, $status, 'a real install exits 0');
Assert::contains('checksum ok', $printed, 'the checksum verified');

$installed = $destination . DIRECTORY_SEPARATOR . 'volucer';
Assert::true(is_file($installed), 'the binary was installed');
Assert::same($payload, file_get_contents($installed), 'the installed bytes are the archived bytes');
Assert::true((int) filesize($installed) > 0, 'the installed file is not empty');

// --- 3. Installing again refuses without --force. ------------------------
// This is the check that stops a second run from silently replacing a working
// binary with a different version.

[$status, $printed] = runCli([
    '--os', 'linux', '--arch', 'x86_64',
    '--location', $destination,
]);
Assert::same(1, $status, 'a second install without --force exits 1');
Assert::contains('already exists', $printed, 'and says why');
Assert::same($payload, file_get_contents($installed), 'the existing binary is untouched');

[$status, $printed] = runCli([
    '--force',
    '--os', 'linux', '--arch', 'x86_64',
    '--location', $destination,
]);
Assert::same(0, $status, '--force replaces it');
Assert::same($payload, file_get_contents($installed), 'and the replacement is correct');

// --- 4. The Windows path, from this machine. -----------------------------
// A zip in the same release, selected by the arm this host is not.

$winDestination = e2eScratch($root, 'windows');
[$status, $printed] = runCli([
    '--os', 'windows', '--arch', 'x86_64',
    '--location', $winDestination,
]);

Assert::same(0, $status, 'the Windows archive installs from a non-Windows host');
Assert::contains('x86_64-pc-windows-msvc', $printed, 'the Windows target was selected');
Assert::same(
    'MZ fixture',
    file_get_contents($winDestination . DIRECTORY_SEPARATOR . 'volucer.exe'),
    'volucer.exe was extracted from the zip',
);

// --- 5. A corrupt download is refused, and leaves nothing behind. --------
// The checksum is the only thing between a hostile or truncated download and
// the operator's filesystem, so this is the most important case here.

$corrupt = e2eScratch($root, 'corrupt');
$asset = $assets . DIRECTORY_SEPARATOR . $prefix . '.tar.gz';
$original = file_get_contents($asset);

// One byte changed, in the middle, and the stored checksum left alone. The
// offset is computed rather than fixed: a gzipped tar is mostly NUL padding and
// compresses to a few hundred bytes, so a literal offset can be past the end.
$bytes = $original;
$flip = intdiv(strlen($bytes), 2);
$bytes[$flip] = $bytes[$flip] === 'X' ? 'Y' : 'X';
Assert::true($bytes !== $original, 'the corrupt fixture really differs from the original');
file_put_contents($asset, $bytes);

[$status, $printed] = runCli([
    '--os', 'linux', '--arch', 'x86_64',
    '--location', $corrupt,
]);

Assert::same(1, $status, 'a corrupt archive exits 1');
Assert::contains('checksum mismatch', $printed, 'and names the checksum');
Assert::contains('Nothing was installed', $printed, 'and says nothing was installed');
Assert::true(
    !is_file($corrupt . DIRECTORY_SEPARATOR . 'volucer'),
    'no binary was installed from a corrupt archive',
);

// The temporary files are cleaned up rather than left in the system temp
// directory, which is what stops a repeated failure from filling a disk.
$stragglers = [];
foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volucer-archive-*') ?: [] as $left) {
    $stragglers[] = $left;
}
Assert::same([], $stragglers, 'a failed download leaves no temporary file behind');

// --- 6. A release missing the checksum is refused before downloading. ----
// Downloading a large archive and only then discovering it cannot be verified
// is the wrong order.

file_put_contents($asset, $original);
$checksumPath = $asset . '.sha256';
$checksumBackup = file_get_contents($checksumPath);
unlink($checksumPath);

$unverifiable = e2eScratch($root, 'unverifiable');
[$status, $printed] = runCli([
    '--os', 'linux', '--arch', 'x86_64',
    '--location', $unverifiable,
]);
Assert::same(1, $status, 'a release with no checksum exits 1');
Assert::contains('cannot be verified', $printed, 'and says why');
Assert::true(
    !is_file($unverifiable . DIRECTORY_SEPARATOR . 'volucer'),
    'nothing was installed without a checksum',
);
file_put_contents($checksumPath, $checksumBackup);

// --- 7. A tag that does not exist is refused by name. --------------------

[$status, $printed] = runCli([
    '--release', '9.9.9',
    '--os', 'linux', '--arch', 'x86_64',
    '--location', e2eScratch($root, 'missing'),
]);
Assert::same(1, $status, 'an unknown version exits 1');
Assert::contains('no release tagged v9.9.9', $printed, 'and names the tag it looked for');

// `--version` means the tool's version, not a release to install. Both spellings
// were the same option in the first version of this command, and the collision
// only showed up when the exit code was asserted rather than the output.
[$status, $printed] = runCli(['--version']);
Assert::same(0, $status, '--version exits 0');
Assert::contains('volucer-cli 0.1.0', $printed, '--version prints the tool version');
Assert::true(
    !str_contains($printed, 'platform'),
    '--version does not start an install',
);
[$status, $printed] = runCli(['-V']);
Assert::same(0, $status, '-V is the same as --version');

// --- 8. A platform with no archive is refused before any request. --------

[$status, $printed] = runCli([
    '--os', 'linux', '--arch', 'arm64', '--alpine',
    '--location', e2eScratch($root, 'alpine'),
]);
Assert::same(1, $status, 'Alpine on arm64 exits 1');
Assert::contains('no musl archive', $printed, 'and says no musl archive exists');

// --- 9. An unknown option is refused rather than ignored. ---------------

[$status, $printed] = runCli(['--nonsense']);
Assert::same(1, $status, 'an unknown option exits 1');
Assert::contains('unknown option', $printed, 'and names the option');

// --- 10. The whole thing again over the stream transport. ----------------
// The stream path is the fallback for a host with no curl, which is the machine
// least able to explain a failure - so it runs here, through the same real
// server, rather than waiting to be exercised for the first time in the field.
// A fallback that has never run is a fallback that does not work.

$streamed = e2eScratch($root, 'stream');
$previousTransport = getenv('VOLUCER_CLI_TRANSPORT');
putenv('VOLUCER_CLI_TRANSPORT=stream');

try {
    [$status, $printed] = runCli([
        '--os', 'linux', '--arch', 'x86_64',
        '--location', $streamed,
    ]);
    Assert::same(0, $status, 'the stream transport installs');
    Assert::same(
        $payload,
        file_get_contents($streamed . DIRECTORY_SEPARATOR . 'volucer'),
        'the stream transport produces the same bytes as curl',
    );

    // The refusals must behave identically over the other transport, because
    // they are the same code. If only one transport were exercised, a
    // difference between them would be invisible.
    file_put_contents($asset, $bytes);
    [$status, $printed] = runCli([
        '--os', 'linux', '--arch', 'x86_64',
        '--location', e2eScratch($root, 'stream-corrupt'),
    ]);
    Assert::same(1, $status, 'the stream transport refuses a corrupt archive');
    Assert::contains('checksum mismatch', $printed, 'and names the checksum');
    file_put_contents($asset, $original);
} finally {
    if ($previousTransport === false) {
        putenv('VOLUCER_CLI_TRANSPORT');
    } else {
        putenv('VOLUCER_CLI_TRANSPORT=' . $previousTransport);
    }
}

// --- cleanup -------------------------------------------------------------

proc_terminate($server);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        @fclose($pipe);
    }
}
proc_close($server);

putenv('VOLUCER_CLI_API');

e2eRemove($root);
foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fixture-*') ?: [] as $left) {
    @unlink($left);
}
foreach (glob(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volucer-extract-*') ?: [] as $left) {
    e2eRemove($left);
}

exit(Assert::report('EndToEndTest'));
