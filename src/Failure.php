<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * A failure the operator is meant to read.
 *
 * `Cli` prints these as `volucer-cli: <message>` with no stack trace, because
 * "no archive for Alpine on arm64" is a sentence, and a trace for it is noise.
 * Anything else that escapes is a bug and keeps its trace.
 */
final class Failure extends \RuntimeException
{
    /**
     * A transport-level failure, with a hint for the one cause that is both
     * common and unrecognisable in its raw form.
     *
     * A PHP built from source on Windows often ships with no CA bundle and no
     * `curl.cainfo` or `openssl.cafile` in `php.ini`. OpenSSL then reports
     * `unable to get local issuer certificate (20)`, which reads like a
     * problem with the server being contacted rather than with the client's
     * configuration. The connection was correctly refused; it is the *reason*
     * that needs saying.
     */
    public static function network(string $url, string $message): self
    {
        $hint = '';
        if (stripos($message, 'certificate') !== false || stripos($message, 'SSL') !== false) {
            $hint = "\n    This is usually an incomplete TLS setup rather than a network problem."
                . "\n    Check that `curl.cainfo` (or `openssl.cafile`) in php.ini points at a CA"
                . "\n    bundle such as cacert.pem.";
        }

        return new self(sprintf('could not reach %s: %s%s', $url, $message, $hint));
    }
}
