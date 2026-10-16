<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * One release tag and the asset names it carries.
 */
final class Release
{
    /**
     * @param list<string> $assets
     */
    public function __construct(
        public readonly string $tag,
        public readonly array $assets,
    ) {
    }

    /**
     * The version without the tag's `v`, which is what appears inside an asset
     * name: `v0.1.0` carries `volucer-0.1.0-x86_64-unknown-linux-gnu.tar.gz`.
     */
    public function version(): string
    {
        return ltrim($this->tag, 'v');
    }

    public function has(string $asset): bool
    {
        return in_array($asset, $this->assets, true);
    }

    /**
     * Compare two tags by semantic version, newest last.
     *
     * Releases are listed newest-first by GitHub, but this does not rely on it:
     * the order a downloader depends on should be established rather than
     * assumed, and a `sort` that cannot order a tag says so by returning 0
     * rather than by silently choosing one.
     */
    public static function compare(string $a, string $b): int
    {
        $parse = static function (string $tag): array {
            // A pre-release sorts below its release: 0.1.0-rc.1 < 0.1.0.
            [$core, $pre] = array_pad(explode('-', ltrim($tag, 'v'), 2), 2, '');

            return [
                array_map('intval', array_pad(explode('.', $core), 3, '0')),
                $pre,
            ];
        };

        [[$aCore, $aPre], [$bCore, $bPre]] = [$parse($a), $parse($b)];

        for ($i = 0; $i < 3; $i++) {
            if ($aCore[$i] !== $bCore[$i]) {
                return $aCore[$i] <=> $bCore[$i];
            }
        }

        if ($aPre === '' && $bPre === '') {
            return 0;
        }
        if ($aPre === '') {
            return 1;
        }
        if ($bPre === '') {
            return -1;
        }

        return strnatcmp($aPre, $bPre);
    }
}
