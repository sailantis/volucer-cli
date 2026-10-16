<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * The host platform, reduced to the two things release selection needs.
 *
 * The reason this class exists at all is that the raw values PHP offers are not
 * a stable vocabulary: `php_uname('m')` reports `AMD64`, `x86_64`, `x64` or
 * `amd64` depending on the machine, so every one of them has to be mapped
 * rather than compared.
 *
 * `RoadRunner\Console\Environment\{OperatingSystem,Architecture}` does the same
 * job for its own release assets, and both were read before writing this. Two
 * of its choices are deliberately *not* copied - see `archFromUname()` and
 * `isAlpine()`.
 */
final class Platform
{
    public const OS_LINUX = 'linux';
    public const OS_DARWIN = 'darwin';
    public const OS_WINDOWS = 'windows';
    public const OS_BSD = 'bsd';

    public const ARCH_X86_64 = 'x86_64';
    public const ARCH_ARM64 = 'arm64';
    public const ARCH_X86_32 = 'x86_32';
    public const ARCH_ARM_32 = 'arm_32';
    public const ARCH_RISCV64 = 'riscv64';

    /**
     * @param bool $alpine For Linux only: whether this is a musl distribution.
     */
    private function __construct(
        public readonly string $os,
        public readonly string $arch,
        public readonly bool $alpine = false,
    ) {
    }

    /**
     * Build a platform explicitly, for tests and for an override passed on the
     * command line. Nothing here reads the environment, so every combination
     * that matters can be asserted from any machine.
     */
    public static function of(string $os, string $arch, bool $alpine = false): self
    {
        if (!in_array($os, [self::OS_LINUX, self::OS_DARWIN, self::OS_WINDOWS, self::OS_BSD], true)) {
            throw new Failure(sprintf('unknown operating system "%s"', $os));
        }

        if (!in_array($arch, [
            self::ARCH_X86_64,
            self::ARCH_ARM64,
            self::ARCH_X86_32,
            self::ARCH_ARM_32,
            self::ARCH_RISCV64,
        ], true)) {
            throw new Failure(sprintf('unknown architecture "%s"', $arch));
        }

        return new self($os, $arch, $alpine && $os === self::OS_LINUX);
    }

    public static function createFromGlobals(): self
    {
        return self::of(
            self::osFromFamily(PHP_OS_FAMILY),
            self::archFromUname(php_uname('m')),
            self::isAlpine(PHP_OS, PHP_OS_FAMILY, php_uname('v'), is_file('/etc/alpine-release')),
        );
    }

    /**
     * `PHP_OS_FAMILY` is one of a closed set, so this is a total function over
     * the platforms PHP exists on.
     */
    public static function osFromFamily(string $family): string
    {
        return match ($family) {
            'Windows' => self::OS_WINDOWS,
            'Darwin' => self::OS_DARWIN,
            'BSD' => self::OS_BSD,
            'Linux' => self::OS_LINUX,
            default => throw new Failure(sprintf(
                'unsupported operating system: %s. volucer ships Linux, macOS and Windows archives.',
                $family,
            )),
        };
    }

    /**
     * Map `php_uname('m')` onto a 64-bit architecture, or onto the *reason* it
     * cannot be used.
     *
     * **Deliberately not RoadRunner's table.** RR maps the uname value `x86`
     * onto `amd64`, but `x86` is the name of the 32-bit Intel architecture -
     * a machine reporting it has a 32-bit userland, and handing it a 64-bit
     * binary produces a "cannot execute binary file" that names neither the
     * cause nor the fix. The 32-bit names are therefore mapped to explicit
     * 32-bit constants so `Target` can refuse them *by name*, which is the
     * difference between an actionable error and a mystery.
     */
    public static function archFromUname(string $unameM): string
    {
        $normalised = strtolower(trim($unameM));

        return match ($normalised) {
            'x86_64', 'amd64', 'x64', 'em64t' => self::ARCH_X86_64,
            'aarch64', 'arm64' => self::ARCH_ARM64,
            'i386', 'i486', 'i586', 'i686', 'x86', 'ia32' => self::ARCH_X86_32,
            'armv6l', 'armv7l', 'armv8l', 'arm' => self::ARCH_ARM_32,
            'riscv64' => self::ARCH_RISCV64,
            default => throw new Failure(sprintf('unsupported architecture: %s', $unameM)),
        };
    }

    /**
     * Whether this is a musl (Alpine) Linux.
     *
     * This matters more than it looks: `PHP_OS_FAMILY` is `Linux` on Alpine and
     * `php_uname('m')` is `x86_64`, so a detector that reads only those picks
     * the **glibc** archive - which downloads, verifies, extracts, and then
     * fails at the loader with a message that points nowhere near the
     * downloader. Getting this wrong is silent until the binary is run.
     *
     * Three signals, because no single one is reliable:
     *
     *  1. `PHP_OS` - RoadRunner's method. It catches a PHP built in a way that
     *     records the distribution, but a stock `apk` build sets it to "Linux".
     *  2. `php_uname('v')` - Alpine's kernel carries "-Alpine" in its version
     *     string. Widely reported rather than verified on this host, so it is
     *     used as a hint and not as the only test.
     *  3. `/etc/alpine-release` - the file every Alpine has and no glibc
     *     distribution ships. This is the backstop that does not depend on
     *     either string.
     *
     * The arguments are passed in rather than read, so every combination is
     * testable from any machine.
     */
    public static function isAlpine(
        string $phpOs,
        string $family,
        string $unameVersion,
        bool $releaseFileExists,
    ): bool {
        if ($family !== 'Linux') {
            return false;
        }

        if (stripos($phpOs, 'alpine') !== false) {
            return true;
        }

        if (stripos($unameVersion, 'alpine') !== false) {
            return true;
        }

        return $releaseFileExists;
    }

    /**
     * A human-readable name for messages and `--help`.
     */
    public function describe(): string
    {
        $libc = $this->os === self::OS_LINUX ? ($this->alpine ? ', musl' : ', glibc') : '';

        return sprintf('%s/%s%s', $this->os, $this->arch, $libc);
    }
}
