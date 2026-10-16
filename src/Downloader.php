<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * The download: pick a release, fetch the archive, fetch the checksum, verify,
 * extract, and install.
 *
 * The ordering is the contract rather than an implementation detail, and it is
 * the reason this is a class with a name rather than a function:
 *
 *  1. The archive is downloaded to a **temporary file**, never to its final
 *     location. A failure at any later step therefore leaves the destination
 *     exactly as it was - an interrupted download cannot leave a truncated
 *     binary where a working one used to be.
 *  2. The checksum is fetched and compared **before anything is extracted**.
 *     Hashing a file that was not trusted is cheap; extracting one is not.
 *  3. Extraction happens into a temporary directory, and the binary is moved
 *     into place only after it exists and is executable. The move is the last
 *     step because it is the only one that changes what the operator has.
 *
 * `--dry-run` stops after (2) and prints what would be installed.
 */
final class Downloader
{
    public function __construct(
        private readonly Releases $releases,
        private readonly Transport $transport,
        private readonly Output $out,
    ) {
    }

    public function install(
        Target $target,
        string $tag,
        string $location,
        bool $force,
        bool $dryRun,
    ): void {
        $release = $this->releases->find($target, $tag);
        $version = $release->version();
        $asset = $target->assetName($version);
        $checksumAsset = $asset . '.sha256';

        $this->out->step(sprintf('release %s', $release->tag));
        $this->out->step(sprintf('asset   %s', $asset));

        $final = $this->destination($location, $target);
        $this->out->step(sprintf('target  %s', $final));

        if (!$force && is_file($final) && !$dryRun) {
            throw new Failure(sprintf(
                '%s already exists. Pass --force to replace it.',
                $final,
            ));
        }

        if ($dryRun) {
            // The checksum is still fetched, because "would this work" is only
            // an answer if the release actually carries the files it claims to.
            $checksum = $this->checksum($release, $checksumAsset);
            $this->out->step(sprintf('checksum sha256 %s', $checksum->hex));
            $this->out->detail('dry run: nothing was downloaded');

            return;
        }

        $archive = Temp::file('volucer-archive');
        try {
            $this->out->step('downloading');
            $this->transport->download(
                $this->releases->assetUrl($release, $asset),
                $archive,
            );

            $checksum = $this->checksum($release, $checksumAsset);

            if (!$checksum->verifyFile($archive)) {
                // The file is discarded rather than kept for inspection: a
                // corrupt archive that is left beside a working install is the
                // thing a later run picks up by accident. The expected and
                // actual digests are printed so the mismatch is diagnosable
                // without re-downloading.
                throw new Failure(sprintf(
                    "checksum mismatch for %s\n    expected: %s\n    actual:   %s\n"
                    . 'The download was discarded. Nothing was installed.',
                    $asset,
                    $checksum->hex,
                    Checksum::ofFile($archive),
                ));
            }
            $this->out->step(sprintf('checksum ok (%s)', substr($checksum->hex, 0, 12)));

            $extracted = $this->extract($archive, $target, $version);
            try {
                $this->installBinary($extracted, $final);
            } finally {
                Temp::remove($extracted);
            }
        } finally {
            @unlink($archive);
        }

        $this->out->detail(sprintf('installed %s', $final));
    }

    /**
     * @return Checksum
     */
    private function checksum(Release $release, string $checksumAsset): Checksum
    {
        if (!$release->has($checksumAsset)) {
            throw new Failure(sprintf(
                '%s carries no %s, so the download cannot be verified and was not attempted',
                $release->tag,
                $checksumAsset,
            ));
        }

        [$status, $body] = $this->transport->get(
            $this->releases->assetUrl($release, $checksumAsset),
        );

        if ($status !== 200) {
            throw new Failure(sprintf('the checksum for %s returned HTTP %d', $checksumAsset, $status));
        }

        return Checksum::parse($body, $checksumAsset);
    }

    /**
     * @return string the directory the archive was extracted into
     */
    private function extract(string $archive, Target $target, string $version): string
    {
        $directory = Temp::directory('volucer-extract');

        try {
            Archive::open($archive)->extractTo($directory);
        } catch (\Throwable $e) {
            Temp::remove($directory);

            throw $e;
        }

        // The archive is packed with a single top-level directory of a known
        // name. Checking it here rather than searching for the binary means a
        // wrongly named archive is refused instead of silently accepted.
        $expected = $directory . DIRECTORY_SEPARATOR . $target->directoryName($version);
        if (!is_dir($expected)) {
            Temp::remove($directory);

            throw new Failure(sprintf(
                'the archive did not contain %s; it may not be the archive this tool built',
                $target->directoryName($version),
            ));
        }

        return $expected;
    }

    private function installBinary(string $extracted, string $final): void
    {
        $binary = $extracted . DIRECTORY_SEPARATOR . self::binaryNameFor($extracted);

        $directory = dirname($final);
        if (!is_dir($directory) && !@mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new Failure(sprintf('could not create %s', $directory));
        }

        if (!@rename($binary, $final)) {
            // Across a filesystem boundary `rename` fails, and the temporary
            // directory is often on a different one. Copying is the fallback
            // rather than the default, because a copy is not atomic.
            if (!@copy($binary, $final)) {
                throw new Failure(sprintf('could not install %s', $final));
            }
        }

        @chmod($final, 0o755);
    }

    /**
     * On Windows the binary inside a zip is `volucer.exe`; the platform that
     * chose the zip is the one that knows that, so the name is taken from the
     * directory rather than assumed from the host.
     */
    private static function binaryNameFor(string $extracted): string
    {
        if (is_file($extracted . DIRECTORY_SEPARATOR . 'volucer.exe')) {
            return 'volucer.exe';
        }

        return 'volucer';
    }

    private function destination(string $location, Target $target): string
    {
        if ($location !== '') {
            // A directory argument means "put it in there", which is how an
            // operator writing `--location /usr/local/bin` expects it to read.
            if (is_dir($location) || str_ends_with($location, DIRECTORY_SEPARATOR)) {
                return rtrim($location, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR . $target->binaryName();
            }

            return $location;
        }

        return getcwd() . DIRECTORY_SEPARATOR . $target->binaryName();
    }
}
