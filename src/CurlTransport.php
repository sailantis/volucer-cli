<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * Transport over `ext-curl`.
 */
final class CurlTransport implements Transport
{
    /** @var array<string,string> headers to send on every request */
    private array $headers = [];

    /**
     * @param array<string,string> $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = $headers;

        return $clone;
    }

    public function get(string $url): array
    {
        $handle = $this->open($url);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($handle);

        if ($body === false) {
            $message = curl_error($handle);
            curl_close($handle);

            throw Failure::network($url, $message);
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);

        return [$status, (string) $body];
    }

    public function download(string $url, string $path): void
    {
        $file = @fopen($path, 'wb');
        if ($file === false) {
            throw new Failure(sprintf('could not write to %s', $path));
        }

        $handle = $this->open($url);
        curl_setopt($handle, CURLOPT_FILE, $file);
        $ok = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $message = curl_error($handle);
        curl_close($handle);
        fclose($file);

        if ($ok === false) {
            // The partial file is removed rather than left behind: a truncated
            // archive on disk is worse than none, because the next run may
            // treat it as a completed download.
            @unlink($path);

            throw new Failure(sprintf('download of %s failed: %s', $url, $message));
        }

        if ($status !== 200) {
            @unlink($path);

            throw new Failure(sprintf('download of %s returned HTTP %d', $url, $status));
        }
    }

    /** @return \CurlHandle */
    private function open(string $url)
    {
        $handle = curl_init($url);
        if ($handle === false) {
            throw new Failure('curl could not be initialised');
        }

        curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true);
        // GitHub serves release assets through a redirect to a different host,
        // which is why redirects are followed on the way to the file as well.
        curl_setopt($handle, CURLOPT_MAXREDIRS, 5);
        curl_setopt($handle, CURLOPT_TIMEOUT, 300);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($handle, CURLOPT_USERAGENT, Cli::USER_AGENT);

        if ($this->headers !== []) {
            $lines = [];
            foreach ($this->headers as $name => $value) {
                $lines[] = $name . ': ' . $value;
            }
            curl_setopt($handle, CURLOPT_HTTPHEADER, $lines);
        }

        return $handle;
    }
}
