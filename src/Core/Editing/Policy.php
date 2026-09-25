<?php

declare(strict_types=1);

namespace TAW\Core\Editing;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * The effective editing policy for a request: every layer resolved to plain
 * settings. Built by Resolver; read by the layers Boot::editing() wires up
 * (and by the Tools → TAW Editing screen).
 */
final class Policy
{
    /**
     * @param array<string, bool>                 $site
     * @param array<string, bool>                 $design
     * @param array<string, bool>                 $features
     * @param array<string, array<string, mixed>> $content  Resolved rules for every post type the policy names.
     * @param list<string>                        $warnings Problems found while resolving (e.g. a bad constant).
     * @param list<string>                        $themeBlocks The theme's own blocks, already added to every allow list.
     */
    public function __construct(
        public readonly string $preset,
        public readonly string $presetSource,
        public readonly string $bypassCapability,
        public readonly array $site,
        public readonly array $design,
        public readonly array $features,
        private readonly array $content,
        public readonly array $warnings = [],
        public readonly array $themeBlocks = [],
    ) {
    }

    /**
     * The content rule for a post type: allow (list of globs, or null for
     * every block), template (or null), lock (false or a LOCKS value) and
     * newPostsOnly. A post type the policy doesn't name gets the open rule.
     *
     * @return array<string, mixed>
     */
    public function content(string $postType): array
    {
        return $this->content[$postType] ?? Presets::layer('content', 'open');
    }

    /**
     * Post types with a rule of their own (default targets, post type
     * definitions and the site's content map).
     *
     * @return list<string>
     */
    public function contentPostTypes(): array
    {
        return array_keys($this->content);
    }

    /**
     * Everything, for display and debugging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'preset'       => $this->preset,
            'presetSource' => $this->presetSource,
            'bypass'       => ['capability' => $this->bypassCapability],
            'themeBlocks'  => $this->themeBlocks,
            'site'         => $this->site,
            'design'       => $this->design,
            'features'     => $this->features,
            'content'      => $this->content,
            'warnings'     => $this->warnings,
        ];
    }
}
