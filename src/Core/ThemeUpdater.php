<?php

declare(strict_types=1);

namespace TAW\Core;

/**
 * Github-based Theme Updater.
 * 
 * Hooks into WordPress's theme update transient to check a Github
 * repository for new relases. When a new version is found,
 * WordPress shows the standard "Update Available" notice.
 * 
 * Usage: 
 * new ThemeUpdater([
 * 'slug' => 'taw-theme',
 * 'github_url' => 'https://api.github.com/repos/your-username/taw-theme/releases/latest',
 * ])
 */

class ThemeUpdater
{
    private string $slug;
    private string $github_url;
    private string $current_version;
    private string $cache_key;

    public function __construct(array $config)
    {
        $this->slug            = $config['slug'] ?? 'taw-theme';
        $this->github_url      = $config['github_url'];
        $this->current_version = wp_get_theme($this->slug)->get('Version') ?: '0.0.0';
        $this->cache_key       = 'taw_update_' . $this->slug;

        // Hook into WordPress's update check
        add_filter('pre_set_site_transient_update_themes', [$this, 'check_for_update']);

        // Provide info for the "View Details" popup
        add_filter('themes_api', [$this, 'theme_info'], 10, 3);
    }

    /**
     * Check GitHub for a newer release.
     * 
     * WordPress calls this periodically when checking for theme updates.
     * We query the GitHub API (with caching) and inject our theme
     * into the update response if a newer version exists.
     */
    public function check_for_update(object $transient): object
    {
        $remote = $this->get_remote_version();

        if (!$remote) {
            return $transient;
        }

        // Compare version
        if (version_compare($this->current_version, $remote['version'], '<')) {
            $transient->response[$this->slug] = [
                'theme' => $this->slug,
                'new_version' => $remote['version'],
                'url' => $remote['homepage'],
                'package' => $remote['download_url'],
            ];
        }

        return $transient;
    }

    /**
     * Provide theme info for the "View Details" mode.
     */
    public function theme_info(false|object $result, string $action, object $args): false|object
    {

        if ($action !== 'theme_information' || ($args->slug ?? '') !== $this->slug) {
            return $result;
        }

        $remote = $this->get_remote_version();

        if (!$remote) {
            return $result;
        }

        return (object) [
            'name'           => 'TAW Theme',
            'slug'           => $this->slug,
            'version'        => $remote['version'],
            'author'         => '<a href="https://mlizardo.com">M. Lizardo</a>',
            'homepage'       => $remote['homepage'],
            'download_link'  => $remote['download_url'],
            'requires'       => '6.0',
            'tested'         => '6.7',
            'requires_php'   => '7.4',
            'last_updated'   => $remote['published_at'],
            'sections'       => [
                'description' => $remote['description'] ?? '',
                'changelog'   => $remote['changelog'] ?? '',
            ]
        ];
    }

    /**
     * Fetch the latest release info from GitHub, with caching.
     *
     * We cache for 6 hours to avoid hitting GitHub's rate limits.
     * The GitHub Releases API returns the tag name as the version,
     * and the first asset (or zipball) as the download URL.
     */
    private function get_remote_version(): ?array
    {
        $cached = get_transient($this->cache_key);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get($this->github_url, [
            'headers' => [
                'Accept' => 'application/vnd.github.v3+json',
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        if (!$release || empty($release['tag_name'])) {
            return null;
        }

        // Strip 'v' prefix from tag (v1.2.0 → 1.2.0)
        $version = ltrim($release['tag_name'], 'v');

        // Prefer the first asset (a built ZIP), fall back to GitHub's auto-generated zipball
        $download_url = !empty($release['assets'][0]['browser_download_url'])
            ? $release['assets'][0]['browser_download_url']
            : $release['zipball_url'];

        $data = [
            'version'      => $version,
            'download_url' => $download_url,
            'homepage'     => $release['html_url'],
            'description'  => $release['body'] ?? '',
            'changelog'    => $release['body'] ?? '',
            'published_at' => $release['published_at'] ?? '',
        ];

        // Cache for 6 hours
        set_transient($this->cache_key, $data, 6 * HOUR_IN_SECONDS);

        return $data;
    }
}
