<?php

declare(strict_types=1);

/**
 * Both transports, against a real local server.
 *
 * The reason this file exists as its own test rather than only as a branch of
 * the end-to-end run: the two transports are one code path apart, and the bug
 * that was found here was invisible under curl. `StreamTransport` read
 * `$http_response_header` in a *different method* from the one that made the
 * request, where the variable does not exist - so every status came back `0`,
 * every response looked like a failure, and the stream path would have been
 * entirely broken on exactly the hosts that have no curl, which are the hosts
 * least able to diagnose it.
 *
 * So the status code is asserted for both, on a success *and* a failure, which
 * is what distinguishes "the transport works" from "the transport returns
 * something".
 */

require __DIR__ . '/../autoload.php';
require __DIR__ . '/Assert.php';

use Volucer\Cli\CurlTransport;
use Volucer\Cli\Failure;
use Volucer\Cli\StreamTransport;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'volucer-transport-' . getmypid();
@mkdir($root, 0o755, true);

function tRemove(string $path): void
{
    if (is_dir($path)) {
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                tRemove($path . DIRECTORY_SEPARATOR . $item);
            }
        }
        @rmdir($path);

        return;
    }
    @unlink($path);
}

// A router with one 200, one 404, one 500, one redirect and one file to fetch.
file_put_contents($root . DIRECTORY_SEPARATOR . 'router.php', <<<'PHP'
    <?php
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    return match ($uri) {
        '/ok' => (function () {
            header('Content-Type: application/json');
            echo '{"ok":true}';
            return true;
        })(),
        '/redirect' => (function () {
            header('Location: /ok', true, 302);
            return true;
        })(),
        '/notfound' => (function () {
            http_response_code(404);
            echo '{"message":"Not Found"}';
            return true;
        })(),
        '/servererror' => (function () {
            http_response_code(500);
            echo 'boom';
            return true;
        })(),
        '/file' => (function () {
            header('Content-Type: application/octet-stream');
            echo 'the file bytes';
            return true;
        })(),
        default => (function () {
            http_response_code(404);
            echo '{}';
            return true;
        })(),
    };
    PHP);

$port = 8137;
$server = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . DIRECTORY_SEPARATOR . 'router.php'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $root,
);

if (!is_resource($server)) {
    fwrite(STDERR, "could not start the fixture server\n");
    exit(1);
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

$base = 'http://127.0.0.1:' . $port;
$deadline = microtime(true) + 10;
while (microtime(true) < $deadline) {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.5);
    if (is_resource($socket)) {
        fclose($socket);
        break;
    }
    usleep(100000);
}

$transports = [
    'curl' => extension_loaded('curl') ? new CurlTransport() : null,
    'stream' => new StreamTransport(),
];

foreach ($transports as $name => $transport) {
    if ($transport === null) {
        // Asserted rather than skipped silently: a transport that is silently
        // absent is how the stream path went untested in the first place.
        Assert::true(false, "the curl transport is available (it is not, so its half is untested)");
        continue;
    }

    // A success: the body arrives and the status is exactly 200. The status is
    // the assertion that caught the defect - the body was always fine.
    [$status, $body] = $transport->get($base . '/ok');
    Assert::same(200, $status, "{$name}: a 200 is reported as 200");
    Assert::same('{"ok":true}', $body, "{$name}: the body arrives");

    // A 404 is a status, not a transport error. A downloader that could not
    // tell the two apart would report "not found" as "could not connect".
    [$status, $body] = $transport->get($base . '/notfound');
    Assert::same(404, $status, "{$name}: a 404 is reported as 404");
    Assert::contains('Not Found', $body, "{$name}: a 404 body is still readable");

    [$status, $body] = $transport->get($base . '/servererror');
    Assert::same(500, $status, "{$name}: a 500 is reported as 500");

    // A redirect is followed, and the status is that of the response that
    // actually arrived rather than of the redirect.
    [$status, $body] = $transport->get($base . '/redirect');
    Assert::same(200, $status, "{$name}: a redirect is followed and reports the final status");
    Assert::same('{"ok":true}', $body, "{$name}: the redirect target's body arrives");

    // download() writes the bytes and reports a non-200 rather than writing a
    // file containing an error page.
    $target = $root . DIRECTORY_SEPARATOR . $name . '-file.bin';
    $transport->download($base . '/file', $target);
    Assert::same('the file bytes', file_get_contents($target), "{$name}: download() writes the bytes");

    $bad = $root . DIRECTORY_SEPARATOR . $name . '-bad.bin';
    Assert::throws(
        fn () => $transport->download($base . '/notfound', $bad),
        "{$name}: download() refuses a 404",
        'HTTP 404',
    );
    Assert::true(!is_file($bad), "{$name}: a refused download leaves no file behind");

    // A host that does not resolve is a transport error, and must not be
    // reported as an HTTP status.
    Assert::throws(
        fn () => $transport->get('http://127.0.0.1:1/never'),
        "{$name}: an unreachable host raises rather than returning a status",
    );
}

proc_terminate($server);
foreach ($pipes as $pipe) {
    if (is_resource($pipe)) {
        @fclose($pipe);
    }
}
proc_close($server);
tRemove($root);

exit(Assert::report('TransportTest'));
