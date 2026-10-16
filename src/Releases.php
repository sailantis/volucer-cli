<?php

declare(strict_types=1);

namespace Volucer\Cli;

/**
 * The GitHub Releases API.
 *
 * The base URL is a constructor argument rather than a constant. That is the
 * single decision that makes this class testable: pointed at a local server it
 * answers from a fixture release, so asset selection, version selection, the
 * checksum and extraction all run for real instead of being described. It is
 * also the same escape hatch RoadRunner's own downloader uses for GitHub
 * Enterprise (`RR_GITHUB_API_URL`), where the API lives at `scheme://host/api/v3`.
 */
final class Releases
{
    public const DEFAULT_API = 'https://api.github.com';

    private function __construct(
        private readonly string $api,
        private readonly string $repository,
        private readonly Transport $transport,
    ) {
    }

    public static function create(?string $api = null, ?Transport $transport = null): self
    {
        return new self(
            rtrim($api ?? self::defaultApi(), '/'),
            Cli::REPOSITORY,
            $transport ?? Cli::transport(),
        );
    }

    /**
     * The API root, overridable per the interface every one of this project's
     * tests needs.
     *
     * `VOLUCER_CLI_API` is the name because the convention used elsewhere in
     * this project is a `VOLUCER_`-prefixed variable for anything a test needs
     * to redirect.
     */
    public static function defaultApi(): string
    {
        $configured = getenv('VOLUCER_CLI_API');

        return $configured === false || $configured === '' ? self::DEFAULT_API : $configured;
    }

    /**
     * Every release tag, oldest first.
     *
     * `per_page=100` is the maximum the API allows and covers this project by
     * two orders of magnitude.
     *
     * @return list<Release>
     */
    public function list(): array
    {
        $url = sprintf('%s/repos/%s/releases?per_page=100', $this->api, $this->repository);
        [$status, $body] = $this->transport->get($url);

        if ($status === 404) {
            throw new Failure(sprintf(
                'no releases found for %s. If you are reading this before the first release, '
                . 'there is nothing to download yet.',
                $this->repository,
            ));
        }

        if ($status !== 200) {
            throw new Failure(sprintf('the releases API returned HTTP %d for %s', $status, $url));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new Failure('the releases API returned something that is not JSON');
        }

        $releases = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry) || !isset($entry['tag_name']) || !is_string($entry['tag_name'])) {
                continue;
            }

            $assets = [];
            foreach ((array) ($entry['assets'] ?? []) as $asset) {
                $name = is_array($asset) ? ($asset['name'] ?? null) : null;
                if (is_string($name)) {
                    $assets[] = $name;
                }
            }

            $releases[] = new Release($entry['tag_name'], $assets);
        }

        usort($releases, static fn (Release $a, Release $b): int => Release::compare($a->tag, $b->tag));

        return $releases;
    }

    /**
     * The newest release that actually carries the asset we need.
     *
     * The "actually carries" part is the reason this does not simply take the
     * last release: a release whose build failed publishes a tag with fewer
     * assets, and the newest tag is not always the newest *usable* one.
     */
    public function newestWith(Target $target): Release
    {
        $candidates = $this->list();

        for ($i = count($candidates) - 1; $i >= 0; $i--) {
            $release = $candidates[$i];
            if ($release->has($target->assetName($release->version()))) {
                return $release;
            }
        }

        $available = $candidates === []
            ? 'none'
            : implode(', ', array_map(static fn (Release $r): string => $r->tag, $candidates));

        throw new Failure(sprintf(
            'no release carries %s for %s. Releases seen: %s.',
            $target->triple,
            $target->extension,
            $available,
        ));
    }

    /**
     * A release by tag, or the newest one when the tag is empty.
     */
    public function find(Target $target, string $tag): Release
    {
        if ($tag === '') {
            return $this->newestWith($target);
        }

        $normalised = str_starts_with($tag, 'v') ? $tag : 'v' . $tag;

        $url = sprintf('%s/repos/%s/releases/tags/%s', $this->api, $this->repository, $normalised);
        [$status, $body] = $this->transport->get($url);

        if ($status === 404) {
            throw new Failure(sprintf('no release tagged %s', $normalised));
        }

        if ($status !== 200) {
            throw new Failure(sprintf('the releases API returned HTTP %d for %s', $status, $url));
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !isset($decoded['tag_name']) || !is_string($decoded['tag_name'])) {
            throw new Failure(sprintf('the release %s could not be read', $normalised));
        }

        $assets = [];
        foreach ((array) ($decoded['assets'] ?? []) as $asset) {
            $name = is_array($asset) ? ($asset['name'] ?? null) : null;
            if (is_string($name)) {
                $assets[] = $name;
            }
        }

        $release = new Release($decoded['tag_name'], $assets);

        if (!$release->has($target->assetName($release->version()))) {
            throw new Failure(sprintf(
                '%s carries no archive for %s',
                $release->tag,
                $target->triple,
            ));
        }

        return $release;
    }

    /**
     * The download URL of an asset.
     *
     * Built rather than taken from the API's `browser_download_url`, because a
     * fixture server and a private mirror both need to answer the same shape,
     * and because the API's URL is absolute to `github.com` regardless of which
     * API host was just asked.
     */
    public function assetUrl(Release $release, string $asset): string
    {
        return sprintf(
            '%s/repos/%s/releases/download/%s/%s',
            $this->api,
            $this->repository,
            $release->tag,
            $asset,
        );
    }
}
