<?php

declare(strict_types=1);

namespace TAW\Core\Content;

// No `if (!defined('ABSPATH')) exit;` guard: the `content:*` CLI
// commands autoload these classes *before* WordPress boots (see Exporter).

/**
 * How a snapshot's references land on this site: the source's attachment,
 * post, term and user IDs mapped to local ones, and its URLs (each media
 * file's, then the site's origin) mapped to local URLs.
 *
 * Posts, terms and users resolve lazily through the snapshot's `refs`
 * (natural keys) and the lookups the importer passes in; only hits are
 * cached, so a post created later in the same run resolves on a second
 * pass. Pure: no WordPress calls of its own, so it's unit-tested directly.
 *
 * Every lookup returns the local ID, `false` when the snapshot names the
 * record but this site doesn't have it (the reference is dropped), or
 * `null` when the snapshot says nothing about it (older snapshots: the
 * value is left as it was).
 */
final class RefMap
{
    /** @var array<int, int> */
    private array $posts = [];

    /** @var array<int, int> */
    private array $terms = [];

    /** @var array<int, int> */
    private array $users = [];

    /** @var array<string, true> natural keys ("type:slug") that didn't resolve since the last {@see self::takeUnresolved()} */
    private array $unresolved = [];

    /** @var list<array{0: string, 1: string}> regex => replacement, media first, then the origin */
    private array $urlRules = [];

    /** @var array<int, true> source attachment IDs this site doesn't have and won't get */
    private array $missingAttachments = [];

    /**
     * @param array<int, int>                                          $attachments source attachment id => local id
     * @param array<int|string, array<string, mixed>>                  $postRefs    snapshot `refs.posts`
     * @param array<int|string, array<string, mixed>>                  $termRefs    snapshot `refs.terms`
     * @param array<int|string, array<string, mixed>>                  $userRefs    snapshot `refs.users`
     * @param (\Closure(string, string): int)|null                     $findPost    (type, slug) => local id, 0 when absent
     * @param (\Closure(string, string): int)|null                     $findTerm    (taxonomy, slug) => local id, 0 when absent
     * @param (\Closure(array<string, mixed>): int)|null               $findUser    {login, email} => local id, 0 when absent
     * @param list<array{from: string, to: string}>                    $mediaUrls   each media file's source URL => local URL
     */
    public function __construct(
        private readonly array $attachments = [],
        private readonly array $postRefs = [],
        private readonly array $termRefs = [],
        private readonly array $userRefs = [],
        private readonly ?\Closure $findPost = null,
        private readonly ?\Closure $findTerm = null,
        private readonly ?\Closure $findUser = null,
        array $mediaUrls = [],
        string $fromOrigin = '',
        string $toOrigin = '',
        string $fromUploads = '',
        array $missingAttachments = [],
    ) {
        $this->urlRules = self::buildUrlRules($mediaUrls, $fromOrigin, $toOrigin, $fromUploads);
        foreach ($missingAttachments as $missing) {
            $this->missingAttachments[(int) $missing] = true;
        }
    }

    /**
     * The local attachment, `false` for a file the snapshot lists that this
     * site doesn't have and won't get (the reference is cleared, never left
     * pointing at an unrelated attachment), or `null` for an ID the
     * snapshot says nothing about.
     */
    public function attachment(int $id): int|false|null
    {
        if (isset($this->attachments[$id])) {
            return $this->attachments[$id];
        }

        return isset($this->missingAttachments[$id]) ? false : null;
    }

    public function post(int $id): int|false|null
    {
        if (isset($this->posts[$id])) {
            return $this->posts[$id];
        }
        $ref = $this->postRefs[$id] ?? $this->postRefs[(string) $id] ?? null;
        if (!is_array($ref) || $this->findPost === null) {
            return null;
        }
        $type = (string) ($ref['type'] ?? '');
        $slug = (string) ($ref['slug'] ?? '');
        $local = $type !== '' && $slug !== '' ? ($this->findPost)($type, $slug) : 0;
        if ($local > 0) {
            return $this->posts[$id] = $local;
        }
        $this->unresolved["{$type}:{$slug}"] = true;

        return false;
    }

    public function term(int $id): int|false|null
    {
        if (isset($this->terms[$id])) {
            return $this->terms[$id];
        }
        $ref = $this->termRefs[$id] ?? $this->termRefs[(string) $id] ?? null;
        if (!is_array($ref) || $this->findTerm === null) {
            return null;
        }
        $local = ($this->findTerm)((string) ($ref['taxonomy'] ?? ''), (string) ($ref['slug'] ?? ''));

        return $local > 0 ? $this->terms[$id] = $local : false;
    }

    public function user(int $id): int|false|null
    {
        if (isset($this->users[$id])) {
            return $this->users[$id];
        }
        $ref = $this->userRefs[$id] ?? $this->userRefs[(string) $id] ?? null;
        if (!is_array($ref) || $this->findUser === null) {
            return null;
        }
        $local = ($this->findUser)($ref);

        return $local > 0 ? $this->users[$id] = $local : false;
    }

    /**
     * $ids mapped through $lookup: resolved IDs replaced, IDs the snapshot
     * names but this site lacks dropped, unknown IDs kept.
     *
     * @param list<int>                    $ids
     * @param callable(int): (int|false|null) $lookup
     * @return list<int>
     */
    public static function mapIds(array $ids, callable $lookup): array
    {
        $out = [];
        foreach ($ids as $id) {
            $local = $lookup((int) $id);
            if ($local === false) {
                continue;
            }
            $out[] = $local ?? (int) $id;
        }

        return $out;
    }

    /** Source URLs in $text rewritten to this site's: media files first, then the origin. */
    public function urls(string $text): string
    {
        foreach ($this->urlRules as [$pattern, $replacement]) {
            if ($text === '') {
                break;
            }
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }

    /**
     * Natural keys ("type:slug") of posts that didn't resolve since the
     * last call, and forget them.
     *
     * @return list<string>
     */
    public function takeUnresolved(): array
    {
        $keys = array_keys($this->unresolved);
        $this->unresolved = [];

        return $keys;
    }

    public function rewritesUrls(): bool
    {
        return $this->urlRules !== [];
    }

    /**
     * Each media URL as a pattern that also matches its size variants
     * (`-300x200`) and the original of a `-scaled` upload, in plain and
     * JSON-escaped (`\/`) form; then the origin, as http or https, not as a
     * prefix of a longer host, and not for files in the source's uploads
     * folder: a file this site has is mapped by its media rule above, and
     * one it doesn't have keeps loading from the source.
     *
     * @param list<array{from: string, to: string}> $mediaUrls
     * @return list<array{0: string, 1: string}>
     */
    private static function buildUrlRules(array $mediaUrls, string $fromOrigin, string $toOrigin, string $fromUploads = ''): array
    {
        $rules = [];
        // Longest first, so `photo-2.jpg` isn't caught by `photo.jpg`'s rule.
        usort($mediaUrls, static fn (array $a, array $b): int => strlen($b['from']) <=> strlen($a['from']));
        foreach ($mediaUrls as $pair) {
            [$from, $to] = [(string) $pair['from'], (string) $pair['to']];
            if ($from === '' || $to === '' || $from === $to) {
                continue;
            }
            [$fromBase, $fromExt] = self::splitUrl($from);
            [$toBase, $toExt] = self::splitUrl($to);
            if ($fromExt === '' || $fromExt !== $toExt) {
                $rules[] = ['#' . preg_quote($from, '#') . '(?![\w.-])#', $to];
                $rules[] = ['#' . preg_quote(str_replace('/', '\/', $from), '#') . '(?![\w.-])#', str_replace('/', '\/', $to)];
                continue;
            }
            foreach ([['/', '/'], ['/', '\/']] as [, $slash]) {
                $fb = str_replace('/', $slash, $fromBase);
                $tb = str_replace('/', $slash, $toBase);
                $rules[] = [
                    '#' . preg_quote($fb, '#') . '(-scaled|-\d+x\d+)?\.' . preg_quote($fromExt, '#') . '(?![\w-])#',
                    self::escapeReplacement($tb) . '${1}.' . $toExt,
                ];
            }
        }

        $fromOrigin = rtrim($fromOrigin, '/');
        $toOrigin = rtrim($toOrigin, '/');
        $host = (string) preg_replace('#^https?://#', '', $fromOrigin);
        if ($host !== '' && $toOrigin !== '' && preg_replace('#^https?://#', '', $toOrigin) !== $host) {
            $uploads = (string) preg_replace('#^https?://' . preg_quote($host, '#') . '#', '', rtrim($fromUploads, '/'));
            $uploads = $uploads !== '' && str_starts_with($uploads, '/') ? $uploads : '/wp-content/uploads';
            $rules[] = ['#https?://' . preg_quote($host, '#') . '(?![\w.-])(?!' . preg_quote($uploads . '/', '#') . ')#', self::escapeReplacement($toOrigin)];
            $rules[] = ['#https?:\\\\/\\\\/' . preg_quote(str_replace('/', '\/', $host), '#') . '(?![\w.-])(?!' . preg_quote(str_replace('/', '\/', $uploads . '/'), '#') . ')#', self::escapeReplacement(str_replace('/', '\/', $toOrigin))];
        }

        return $rules;
    }

    /**
     * A URL's path without its extension (and without a `-scaled` suffix),
     * and the extension.
     *
     * @return array{0: string, 1: string}
     */
    private static function splitUrl(string $url): array
    {
        if (!preg_match('#^(.*/[^/]+?)\.([A-Za-z0-9]{1,5})$#', $url, $m)) {
            return [$url, ''];
        }

        return [(string) preg_replace('#-scaled$#', '', $m[1]), $m[2]];
    }

    private static function escapeReplacement(string $s): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\$'], $s);
    }
}
