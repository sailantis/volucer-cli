<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * How bytes are fetched. One interface, two implementations, so that the
 * download path can run against a local server in the tests without either
 * implementation being stubbed out.
 */
interface Transport
{
    /**
     * @return array{0: int, 1: string} status code and body
     *
     * @throws Failure On a transport-level error, never on an HTTP status.
     */
    public function get(string $url): array;

    /**
     * Download to a file, so an archive never has to be held in memory.
     *
     * @throws Failure
     */
    public function download(string $url, string $path): void;
}
