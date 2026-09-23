<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * The preset ladder for editing policies (ADR-0005).
 *
 * A site picks one level, open → guided → structured → locked, and every
 * layer gets that level's settings unless the policy overrides it:
 *
 *   content   — post content, per post type: allowed blocks, starting
 *               template, templateLock. Presets apply to DEFAULT_TARGETS
 *               only; every other post type stays open unless configured.
 *   site      — site structure (FSE): what can be edited in the Site Editor.
 *   design    — theme.json tokens: which custom (non-preset) values the
 *               design tools allow.
 *   features  — editor features: code editor, Custom HTML, block directory…
 *
 * For site, design and features every setting is a boolean meaning "allowed"
 * (true) or "locked" (false). PresetsTest snapshots this whole table, so a
 * preset can't change without a failing test: sites rely on what each level
 * means.
 */
final class Presets
{
    public const LEVELS = ['open', 'guided', 'structured', 'locked'];

    public const LAYERS = ['content', 'site', 'design', 'features'];

    /** The level a site gets when nothing chooses one. */
    public const DEFAULT_LEVEL = 'open';

    /**
     * Post types the preset's content level applies to. Posts and data post
     * types keep the normal editor unless the policy names them (the
     * ml-theme ADR-0008 lesson: locking every post type broke blog posts).
     */
    public const DEFAULT_TARGETS = ['page'];

    /** Values for a content rule's "lock" (false = unlocked). */
    public const LOCKS = ['insert', 'contentOnly', 'all'];

    /**
     * Core blocks a client can safely compose pages with. Custom HTML, the
     * classic editor, shortcodes and code blocks are left out on purpose.
     */
    public const CURATED_BLOCKS = [
        'core/paragraph', 'core/heading', 'core/list', 'core/list-item', 'core/quote', 'core/pullquote',
        'core/image', 'core/gallery', 'core/video', 'core/audio', 'core/file', 'core/cover', 'core/media-text',
        'core/buttons', 'core/button', 'core/columns', 'core/column', 'core/group', 'core/separator',
        'core/spacer', 'core/table', 'core/details', 'core/embed', 'core/block',
    ];

    /** Keys of each boolean layer, in display order. */
    public const SETTINGS = [
        'site'     => ['templates', 'templateParts', 'globalStyles', 'navigation', 'templateMode', 'siteEditor'],
        'design'   => [
            'customColors', 'customGradients', 'customFontSizes', 'dropCap',
            'customSpacing', 'customLineHeight', 'border', 'shadow', 'duotone',
        ],
        'features' => ['codeEditor', 'customHtml', 'blockDirectory', 'openverse', 'remotePatterns', 'corePatterns', 'blockLocking'],
    ];

    /** Keys a content rule can set (besides "level"). */
    public const CONTENT_KEYS = ['allow', 'template', 'lock', 'newPostsOnly'];

    /**
     * What each level locks, per boolean layer. Anything not listed stays
     * allowed. Each level includes everything the level before it locks.
     */
    private const LOCKED = [
        'site' => [
            'open'       => [],
            'guided'     => ['globalStyles'],
            'structured' => ['globalStyles', 'templates', 'templateParts', 'templateMode'],
            'locked'     => ['globalStyles', 'templates', 'templateParts', 'templateMode', 'navigation', 'siteEditor'],
        ],
        'design' => [
            'open'       => [],
            'guided'     => ['customColors', 'customGradients', 'customFontSizes', 'dropCap'],
            'structured' => [
                'customColors', 'customGradients', 'customFontSizes', 'dropCap',
                'customSpacing', 'customLineHeight', 'border', 'shadow', 'duotone',
            ],
            'locked'     => [
                'customColors', 'customGradients', 'customFontSizes', 'dropCap',
                'customSpacing', 'customLineHeight', 'border', 'shadow', 'duotone',
            ],
        ],
        'features' => [
            'open'       => [],
            'guided'     => ['codeEditor', 'customHtml', 'blockDirectory', 'openverse', 'remotePatterns'],
            'structured' => ['codeEditor', 'customHtml', 'blockDirectory', 'openverse', 'remotePatterns', 'corePatterns', 'blockLocking'],
            'locked'     => ['codeEditor', 'customHtml', 'blockDirectory', 'openverse', 'remotePatterns', 'corePatterns', 'blockLocking'],
        ],
    ];

    /**
     * A layer's settings at a level.
     *
     * For site/design/features: array<string, bool> keyed by SETTINGS.
     * For content: the content rule — allow (list of block globs, or null for
     * every block), template (null = none), lock (false or one of LOCKS) and
     * newPostsOnly.
     *
     * @return array<string, mixed>
     * @throws \InvalidArgumentException For an unknown layer or level.
     */
    public static function layer(string $layer, string $level): array
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown editing level "%s".', $level));
        }

        if ($layer === 'content') {
            return [
                'allow'        => $level === 'open' ? null : self::CURATED_BLOCKS,
                'template'     => null,
                'lock'         => match ($level) {
                    'structured' => 'contentOnly',
                    'locked'     => 'all',
                    default      => false,
                },
                'newPostsOnly' => true,
            ];
        }

        if (!isset(self::SETTINGS[$layer])) {
            throw new \InvalidArgumentException(sprintf('Unknown editing layer "%s".', $layer));
        }

        $settings = [];
        foreach (self::SETTINGS[$layer] as $key) {
            $settings[$key] = !in_array($key, self::LOCKED[$layer][$level], true);
        }

        return $settings;
    }
}
