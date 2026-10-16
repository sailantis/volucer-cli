<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * Argument parsing and the entry point.
 *
 * Hand-written rather than taken from `symfony/console`: the whole tool is one
 * command, and a console framework would be a larger dependency than the
 * feature it supports. Requiring this package must not pull a dependency tree
 * into a project that asked only for a server binary.
 */
final class Cli
{
    public const REPOSITORY = 'sailantis/volucer';
    public const USER_AGENT = 'volucer-cli';
    public const VERSION = '0.1.0';

    /**
     * Curl when it is available, the stream wrapper otherwise.
     *
     * Curl is a preference and not a dependency: this package's only hard
     * requirements are `ext-hash` and `ext-json`, so a host without it still
     * works.
     *
     * `VOLUCER_CLI_TRANSPORT` forces one of the two. It exists because **a
     * fallback that has never run is a fallback that does not work**: every
     * test here would otherwise exercise curl only, and the stream path would
     * first execute on a machine that has no curl - which is precisely the
     * machine least able to explain a failure. It doubles as a way to tell a
     * TLS problem apart from a curl-specific one.
     *
     * @throws Failure When the variable names something that is not a transport.
     */
    public static function transport(): Transport
    {
        $forced = getenv('VOLUCER_CLI_TRANSPORT');

        if ($forced !== false && $forced !== '') {
            return match (strtolower($forced)) {
                'curl' => extension_loaded('curl')
                    ? new CurlTransport()
                    : throw new Failure('VOLUCER_CLI_TRANSPORT=curl, but this PHP has no curl extension'),
                'stream', 'streams' => new StreamTransport(),
                default => throw new Failure(sprintf(
                    'VOLUCER_CLI_TRANSPORT is "%s"; expected "curl" or "stream"',
                    $forced,
                )),
            };
        }

        return extension_loaded('curl') ? new CurlTransport() : new StreamTransport();
    }

    /**
     * @param list<string> $argv
     */
    public static function main(array $argv, ?Output $out = null): int
    {
        $out ??= new Output();

        try {
            return self::run($argv, $out);
        } catch (Failure $failure) {
            $out->error($failure->getMessage());

            return 1;
        } catch (\Throwable $unexpected) {
            // Not a `Failure`, so it is a bug in this tool rather than
            // something the operator can act on. The trace is worth having.
            $out->error(sprintf('%s', $unexpected));

            return 1;
        } finally {
            Temp::cleanup();
        }
    }

    /**
     * @param list<string> $argv
     */
    private static function run(array $argv, Output $out): int
    {
        $args = array_slice($argv, 1);

        if ($args === [] || in_array($args[0], ['-h', '--help', 'help'], true)) {
            $out->line(self::usage());

            return 0;
        }

        if (in_array($args[0], ['-V', '--version', 'version'], true)) {
            $out->line(sprintf('volucer-cli %s', self::VERSION));

            return 0;
        }

        // `get-binary` is accepted for two reasons: it is what a RoadRunner
        // user's fingers will type, and an unknown-command error for it would
        // be a bad first impression. It is not advertised in the usage.
        //
        // An argument that starts with `--` is an option for the default
        // command rather than a command name, so `volucer-cli --dry-run` reads
        // the way it looks like it should.
        if (str_starts_with($args[0], '--')) {
            $options = self::parse($args);
        } else {
            if (!in_array($args[0], ['install', 'get-binary'], true)) {
                throw new Failure(sprintf(
                    "unknown command '%s'. Run `volucer-cli --help`.",
                    $args[0],
                ));
            }

            array_shift($args);
            $options = self::parse($args);
        }

        $platform = self::platform($options);
        $target = Target::for($platform);
        $out->step(sprintf('platform %s -> %s', $platform->describe(), $target->triple));

        $downloader = new Downloader(
            Releases::create($options['api'] ?? null),
            self::transport(),
            $out,
        );

        $downloader->install(
            $target,
            $options['release'] ?? '',
            $options['location'] ?? '',
            isset($options['force']),
            isset($options['dry-run']),
        );

        return 0;
    }

    /**
     * The platform, honouring the two overrides.
     *
     * The overrides exist so that every platform can be checked from any
     * machine, which is the difference between "the arm64 path is untested" and
     * "the arm64 path is a table entry in a test". They are not a feature for
     * downloading a foreign binary: a downloaded Linux binary does not run on
     * Windows, and the tool says so rather than pretending otherwise.
     */
    private static function platform(array $options): Platform
    {
        $os = $options['os'] ?? null;
        $arch = $options['arch'] ?? null;

        if ($os === null && $arch === null) {
            return Platform::createFromGlobals();
        }

        $detected = Platform::createFromGlobals();

        return Platform::of(
            $os ?? $detected->os,
            $arch ?? $detected->arch,
            isset($options['alpine']),
        );
    }

    /**
     * @param list<string> $args
     *
     * @return array<string,string>
     */
    private static function parse(array $args): array
    {
        $options = [];
        $withValue = ['release', 'location', 'api', 'os', 'arch'];
        $flags = ['force', 'dry-run', 'alpine'];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];

            if ($arg === '--') {
                break;
            }

            if (!str_starts_with($arg, '--')) {
                throw new Failure(sprintf("unexpected argument '%s'", $arg));
            }

            $name = substr($arg, 2);
            $inline = null;
            if (str_contains($name, '=')) {
                [$name, $inline] = explode('=', $name, 2);
            }

            if (in_array($name, $flags, true)) {
                if ($inline !== null) {
                    throw new Failure(sprintf('--%s does not take a value', $name));
                }
                $options[$name] = '1';
                continue;
            }

            if (!in_array($name, $withValue, true)) {
                throw new Failure(sprintf(
                    "unknown option '--%s'. Run `volucer-cli --help`.",
                    $name,
                ));
            }

            if ($inline !== null) {
                $options[$name] = $inline;
                continue;
            }

            $value = $args[$i + 1] ?? null;
            if ($value === null || str_starts_with($value, '--')) {
                throw new Failure(sprintf('--%s needs a value', $name));
            }

            $options[$name] = $value;
            $i++;
        }

        return $options;
    }

    public static function usage(): string
    {
        return <<<'TEXT'
            volucer-cli - download the volucer binary for this platform

            USAGE
              volucer-cli [install] [options]
              volucer-cli --version     print this tool's version
              volucer-cli --help        this text

            OPTIONS
              --release <tag>     Release to install, e.g. 0.1.0 or v0.1.0.
                                  The newest release with a matching archive by default.
              --location <path>   Where to put the binary. A directory, or a full
                                  path to the file. The current directory by default.
              --force             Replace an existing binary.
              --dry-run           Resolve the release and its checksum, download
                                  nothing, and install nothing.
              --api <url>         GitHub API root. Defaults to https://api.github.com,
                                  or the VOLUCER_CLI_API environment variable.
            For a foreign platform, for cross-checks and for the test suite:
              --os <linux|darwin|windows>   Override the detected system.
              --arch <x86_64|arm64>         Override the detected architecture.
              --alpine                      Treat this as a musl Linux.

            EXIT STATUS
              0  installed, or --dry-run resolved cleanly
              1  refused, and the reason is on stderr

            ENVIRONMENT
              VOLUCER_CLI_API        GitHub API root, for a mirror or a local test server.
              VOLUCER_CLI_TRANSPORT  Force "curl" or "stream" instead of preferring curl.

            The download is verified against the release's own SHA-256 before
            anything is extracted, and the archive is written to a temporary file
            until it verifies, so a failure leaves your existing binary untouched.
            TEXT;
    }
}
