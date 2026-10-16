<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * The Rust target triple, and the archive extension, that a host needs.
 *
 * These are the two halves of a release asset name:
 *
 *     volucer-<version>-<target>.<extension>
 *
 * The list below is exactly the matrix in the server repository's
 * `.github/workflows/release.yml`. **If that matrix changes, this file has to
 * change with it** - and the tests assert the six names, so the two cannot
 * drift apart silently.
 *
 * The important property of this class is that it refuses by *name*. Every
 * unsupported combination here is one that would otherwise select an archive
 * which downloads, verifies against a real checksum, extracts to a real binary,
 * and only then fails - at the loader, or on first instruction, far from the
 * cause. An honest refusal is worth more than a plausible wrong answer.
 */
final class Target
{
    public const EXT_TAR_GZ = 'tar.gz';
    public const EXT_ZIP = 'zip';

    private function __construct(
        public readonly string $triple,
        public readonly string $extension,
    ) {
    }

    /**
     * @throws Failure When this platform has no release archive.
     */
    public static function for(Platform $platform): self
    {
        if ($platform->os === Platform::OS_BSD) {
            throw new Failure(
                'no archive for BSD. volucer ships Linux, macOS and Windows builds.',
            );
        }

        if ($platform->os === Platform::OS_WINDOWS) {
            if ($platform->arch !== Platform::ARCH_X86_64) {
                throw new Failure(sprintf(
                    'no archive for Windows on %s. The only Windows build is x86_64.',
                    $platform->arch,
                ));
            }

            return new self('x86_64-pc-windows-msvc', self::EXT_ZIP);
        }

        return match ($platform->os) {
            Platform::OS_DARWIN => self::forDarwin($platform),
            Platform::OS_LINUX => self::forLinux($platform),
            default => throw new Failure(sprintf('no archive for %s', $platform->os)),
        };
    }

    private static function forDarwin(Platform $platform): self
    {
        return match ($platform->arch) {
            Platform::ARCH_X86_64 => new self('x86_64-apple-darwin', self::EXT_TAR_GZ),
            Platform::ARCH_ARM64 => new self('aarch64-apple-darwin', self::EXT_TAR_GZ),
            default => throw new Failure(sprintf(
                'no archive for macOS on %s. volucer ships x86_64 and arm64 builds.',
                $platform->arch,
            )),
        };
    }

    private static function forLinux(Platform $platform): self
    {
        // The musl builds exist for arm64 upstream of us in principle, but this
        // project ships `aarch64-unknown-linux-gnu` only, so there is genuinely
        // nothing to download. Falling back to the glibc archive would be the
        // one "helpful" choice that is guaranteed broken, so the refusal is
        // explicit and says which builds do exist.
        if ($platform->alpine && $platform->arch === Platform::ARCH_ARM64) {
            throw new Failure(
                'no musl archive for Linux on arm64. Available: x86_64 musl (Alpine), '
                . 'x86_64 and arm64 glibc, x86_64 and arm64 macOS, x86_64 Windows.',
            );
        }

        if ($platform->arch !== Platform::ARCH_X86_64 && $platform->arch !== Platform::ARCH_ARM64) {
            throw new Failure(sprintf(
                'no archive for Linux on %s. volucer ships 64-bit builds only.',
                $platform->arch,
            ));
        }

        if ($platform->arch === Platform::ARCH_ARM64) {
            return new self('aarch64-unknown-linux-gnu', self::EXT_TAR_GZ);
        }

        return $platform->alpine
            ? new self('x86_64-unknown-linux-musl', self::EXT_TAR_GZ)
            : new self('x86_64-unknown-linux-gnu', self::EXT_TAR_GZ);
    }

    /**
     * The asset filename for a version, which is the name `stage-archive.sh`
     * produces on the other side.
     *
     * Both halves come from one place: `staging/scripts` in the server build
     * `name="volucer-${version}-${TARGET}"` and then append `.${ARCHIVE}`.
     */
    public function assetName(string $version): string
    {
        return sprintf('volucer-%s-%s.%s', $version, $this->triple, $this->extension);
    }

    /**
     * The directory a correctly built archive extracts to, which is the same
     * name without the extension: the archives are packed with a single
     * top-level directory rather than scattering files into the extractor's
     * working directory.
     */
    public function directoryName(string $version): string
    {
        return sprintf('volucer-%s-%s', $version, $this->triple);
    }

    /**
     * The binary's filename inside the extracted directory.
     */
    public function binaryName(): string
    {
        return $this->extension === self::EXT_ZIP ? 'volucer.exe' : 'volucer';
    }
}
