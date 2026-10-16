# volucer-cli

Downloads the [volucer](https://github.com/sailantis/volucer) binary for the platform you are on,
verifies its checksum, and installs it.

The server itself is a single Rust binary. This package is the Composer-shaped way to get it, so a
PHP project can say `composer require` and have a working server.

```console
composer require --dev sailantis/volucer-cli
vendor/bin/volucer-cli
```

That is the whole idea. Nothing runs on `composer install` - you run the downloader when you want
the binary, and it tells you what it did.

## Why a separate package

`composer require sailantis/volucer` would download a `composer.json` and nothing else. A Composer
package of `"type": "metapackage"` with no `bin` cannot carry a binary, so the download has to
happen in a package that *has* one. This is the same shape RoadRunner uses: `spiral/roadrunner` is a
metapackage and [`roadrunner/cli`](https://packagist.org/packages/roadrunner/cli) is what actually
fetches the server.

The binary is not committed to this repository, and not fetched during install. Either would make
`composer install` slow, offline-hostile, and - at roughly 40 MB per platform, times every project -
a bad trade.

## What it does, in order

1. Works out which release asset this machine needs.
2. Fetches the archive to a **temporary file**.
3. Fetches the release's own `SHA-256` and compares it, **before anything is extracted**.
4. Extracts into a temporary directory, and only then moves the binary into place.

The order is the point. A failure at any step leaves your existing binary exactly as it was: an
interrupted download cannot leave a truncated binary where a working one used to be, and an archive
that does not verify is never extracted at all.

## Usage

```
volucer-cli [install] [options]

  --release <tag>     Release to install, e.g. 0.1.0 or v0.1.0.
                      The newest release with a matching archive by default.
  --location <path>   Where to put the binary. A directory, or a full path to
                      the file. The current directory by default.
  --force             Replace an existing binary.
  --dry-run           Resolve the release and its checksum, download nothing,
                      and install nothing.
  --api <url>         GitHub API root. Defaults to https://api.github.com, or the
                      VOLUCER_CLI_API environment variable.

  --version           Print this tool's version.
  --help              This text.
```

Exit status is `0` on success and `1` on a refusal, with the reason on stderr. `--release`, not
`--version`, selects a release - `--version` is the tool's own version, as it is everywhere else.

```console
$ vendor/bin/volucer-cli --location ./bin
  platform linux/x86_64, glibc -> x86_64-unknown-linux-gnu
  release v0.1.0
  asset   volucer-0.1.0-x86_64-unknown-linux-gnu.tar.gz
  target  /srv/app/bin/volucer
  downloading
  checksum ok (5d98cbf1f9e5)
installed /srv/app/bin/volucer
```

## The binary is named `volucer-cli`

Composer puts a PHP proxy for this tool in `vendor/bin`. If the tool and the binary it installs
shared a name, downloading would overwrite the proxy, and the next `vendor/bin/volucer` would run
the *server* with `get-binary` as an argument. So the tool is `volucer-cli` and the binary it
installs is `volucer`, and the two never collide.

## Supported platforms

The six archives the server's release workflow builds. A platform outside this list is refused **by
name**, before any request is made:

| system | architecture | archive |
| --- | --- | --- |
| Linux (glibc) | x86_64 | `x86_64-unknown-linux-gnu.tar.gz` |
| Linux (musl / Alpine) | x86_64 | `x86_64-unknown-linux-musl.tar.gz` |
| Linux (glibc) | arm64 | `aarch64-unknown-linux-gnu.tar.gz` |
| macOS | arm64 | `aarch64-apple-darwin.tar.gz` |
| macOS | x86_64 | `x86_64-apple-darwin.tar.gz` |
| Windows | x86_64 | `x86_64-pc-windows-msvc.zip` |

**Alpine detection is not trivial, and getting it wrong is silent.** On Alpine, `PHP_OS_FAMILY` is
`Linux` and `php_uname('m')` is `x86_64`, so the obvious check selects the **glibc** archive - which
downloads, verifies, extracts, and then fails at the loader with a message that points nowhere near
the downloader. Three signals are used instead: the `PHP_OS` string, the kernel version string, and
the presence of `/etc/alpine-release`.

Alpine on **arm64** has no archive, and is refused rather than quietly given the glibc build.

## Requirements

PHP 8.2 or newer, with `ext-hash` and `ext-json`. Both are in every standard build.

`ext-curl` is used when present and the stream wrapper when it is not, so curl is not required.
`ext-zip` is needed only to install a Windows archive from a non-Windows host; a Windows machine has
it because the PHP builds there include it.

**Nothing else.** No `symfony/console`, no HTTP client, no lockfile. Requiring this package adds one
directory to `vendor/`, and a `composer install` in a project that never runs the downloader does
nothing at all.

## If it cannot reach GitHub

A PHP built from source on Windows often ships with no CA bundle and no `curl.cainfo` or
`openssl.cafile` in `php.ini`. The connection is then correctly refused with
`unable to get local issuer certificate`, which reads like a problem with GitHub rather than with
the local configuration - so the error names the setting to check.

## Security

The input to this tool is a file fetched over the network, so extraction is a boundary rather than a
convenience. An archive is rejected whole, before anything is written, if it contains:

- a path that escapes the destination - `..`, an absolute path, or a Windows drive letter;
- a symlink or hardlink, which writes outside without any `..` appearing in a name;
- a second archive inside the first, which would be a second parser nobody inspected;
- an implausible entry count or total uncompressed size.

Both formats are read the same way on purpose. `PharData` normalises a stored `../evil.txt` into a
directory named `..` and reports no payload, while `ZipArchive` reports the name verbatim - so a
check written against `PharData`'s own listing passes while defending nothing, for tars. Tar headers
are therefore read directly, and one check guards both formats.

## Development

```console
php tests/run.php
```

No PHPUnit: the package is stdlib-only, and a suite that needed a dependency would undercut that.

The suite runs against a **local HTTP server** serving a fixture release, so the whole download path
- asset selection, the real checksum, real extraction, and every refusal - is exercised without a
release existing. A fixture server is not a stub: every class under test is the shipping one, and
only the origin of the bytes is local. Both transports run against it, because a fallback that has
never run is a fallback that does not work.

## License

MIT. See [LICENSE](LICENSE).
