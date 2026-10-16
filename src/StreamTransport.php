<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * Transport over the stream wrapper, for a host without `ext-curl`.
 *
 * Two things are less pleasant than curl, and both are handled here rather than
 * left to the caller: the status has to be read back out of
 * `$http_response_header`, and the error text arrives on the error channel
 * rather than as a return value.
 *
 * **`$http_response_header` is a local variable of the calling scope**, not a
 * global - the HTTP wrapper populates it in the function that made the request.
 * Reading it anywhere else yields an empty array, and an empty array of headers
 * means a status of `0`, which every caller then reads as "not 200". That is not
 * a cosmetic difference: on a host without curl it would make a successful API
 * response look like a server error and every download look like a failed one,
 * so the tool would be entirely broken there and nowhere else. The status is
 * therefore captured *inside* the method that performs the request, and both
 * methods that make one do it this way.
 */
final class StreamTransport implements Transport
{
    /** @var array<string,string> */
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
        $body = @file_get_contents($url, false, $this->context());
        if ($body === false) {
            $error = error_get_last();

            throw Failure::network($url, $error['message'] ?? 'the request failed');
        }

        // Captured here, in the scope that made the request, because this is
        // the only scope the variable exists in.
        return [$this->status($http_response_header ?? []), $body];
    }

    public function download(string $url, string $path): void
    {
        $source = @fopen($url, 'rb', false, $this->context());
        if ($source === false) {
            $error = error_get_last();

            throw Failure::network($url, $error['message'] ?? 'the request failed');
        }

        $status = $this->status($http_response_header ?? []);

        if ($status !== 200) {
            fclose($source);
            @unlink($path);

            throw new Failure(sprintf('download of %s returned HTTP %d', $url, $status));
        }

        $target = @fopen($path, 'wb');
        if ($target === false) {
            fclose($source);

            throw new Failure(sprintf('could not write to %s', $path));
        }

        $copied = stream_copy_to_stream($source, $target);
        fclose($source);
        fclose($target);

        if ($copied === false) {
            @unlink($path);

            throw new Failure(sprintf('download of %s was interrupted', $url));
        }
    }

    /**
     * @return resource
     */
    private function context()
    {
        return stream_context_create(['http' => [
            'method' => 'GET',
            'header' => $this->headerLines(),
            'user_agent' => Cli::USER_AGENT,
            'follow_location' => 1,
            'max_redirects' => 5,
            'timeout' => 300,
            // Without this a non-200 status raises a warning and returns false
            // instead of the body, so the caller would see a transport error
            // where the server sent a perfectly clear answer.
            'ignore_errors' => true,
        ]]);
    }

    private function headerLines(): string
    {
        $lines = [];
        foreach ($this->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }

        return implode("\r\n", $lines);
    }

    /**
     * @param list<string> $responseHeaders
     */
    private function status(array $responseHeaders): int
    {
        // A redirect chain leaves several status lines; the last is the
        // response that actually arrived.
        $status = 0;
        foreach ($responseHeaders as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }
}
