<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * The checksum that travels beside an archive.
 *
 * The server writes it in the same step as the archive - `stage-archive.sh`
 * runs `sha256sum "${name}.${ARCHIVE}" >"${name}.${ARCHIVE}.sha256"` - so the
 * two cannot come to describe different bytes. This side reads what
 * `sha256sum` writes, which is `<64 hex><two spaces><filename>\n`, and also
 * tolerates the single-space form and a lone hash, because the format a caller
 * happens to produce is not worth refusing a correct checksum over.
 */
final class Checksum
{
    private function __construct(
        public readonly string $algorithm,
        public readonly string $hex,
    ) {
    }

    /**
     * @throws Failure When the file does not contain a usable digest.
     */
    public static function parse(string $contents, string $what): self
    {
        foreach (explode("\n", $contents) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // An alternative form names its algorithm first: `SHA256 (file) = <hex>`.
            if (preg_match('/^([A-Za-z0-9-]+)\s*\((.*)\)\s*=\s*([0-9a-fA-F]+)$/', $line, $m) === 1) {
                return new self(strtolower($m[1]), strtolower($m[3]));
            }

            if (preg_match('/^([0-9a-fA-F]{32,128})(?:\s+\*?\s*.*)?$/', $line, $m) === 1) {
                return new self('sha256', strtolower($m[1]));
            }

            throw new Failure(sprintf('%s does not look like a checksum: %s', $what, $line));
        }

        throw new Failure(sprintf('%s is empty', $what));
    }

    /**
     * Hash a file and compare, in constant time.
     *
     * `hash_file` streams, so a large archive is never held in memory to be
     * hashed.
     */
    public function verifyFile(string $path): bool
    {
        return $this->verify(hash_file($this->algorithm, $path) ?: '');
    }

    public function verify(string $actualHex): bool
    {
        return hash_equals($this->hex, strtolower($actualHex));
    }

    /**
     * The digest of a file, for writing a checksum of our own.
     */
    public static function ofFile(string $path): string
    {
        return hash_file('sha256', $path) ?: '';
    }
}
