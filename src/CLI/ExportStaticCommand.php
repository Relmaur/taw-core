<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Export the site as a static HTML/CSS/JS bundle for edge hosting
 * (Cloudflare Pages, Vercel, etc.) — dynamic endpoints (forms, search)
 * stay on the WordPress origin and are reached over REST/AJAX; see
 * TAW\Core\Rest\Cors for the opt-in cross-origin wiring those need once
 * the frontend moves to a different domain.
 *
 * Needs WordPress fully booted (get_permalink(), WP_Query, wp_upload_dir())
 * — locates and loads wp-load.php itself, same pattern as InspectCommand.
 *
 * Each published page/post is fetched over HTTP (wp_remote_get against its
 * own permalink) rather than rendered in-process, deliberately: this is
 * exactly the request a real visitor would make, so whatever a browser
 * would see — including anything hooked onto template_redirect/the_content
 * by plugins — is what gets frozen into the export. Rendering templates
 * directly in-process would risk missing that.
 *
 * PORTABILITY NOTE: same pattern as MakeBlockCommand — this class lives in
 * vendor/taw/core/ and receives the theme root via constructor injection.
 */
class ExportStaticCommand extends Command
{
    private const POST_TYPES = ['page', 'post'];

    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('export:static')
            ->setDescription('Export published pages/posts as a static HTML bundle for edge hosting')
            ->setHelp(<<<'HELP'
                Fetches every published page and post over HTTP (its own permalink),
                rewrites absolute site-URL references, and writes the result to
                <dir>/<slug>/index.html — plus the built Vite assets (dist/) and
                media uploads, so the export directory is a self-contained bundle
                ready to deploy.

                Dynamic behavior (forms, search) is NOT exported — it keeps hitting
                this WordPress install's REST/AJAX endpoints over the network. Those
                need TAW_HEADLESS_ORIGINS configured in wp-config.php once the export
                is served from a different domain — see TAW\Core\Rest\Cors.

                Examples:
                  <info>php bin/taw export:static</info>
                  <info>php bin/taw export:static --dir=/tmp/build</info>
                  <info>php bin/taw export:static --prod-url=https://my-site.pages.dev</info>
                HELP)
            ->addOption(
                'dir',
                null,
                InputOption::VALUE_REQUIRED,
                'Output directory',
                null
            )
            ->addOption(
                'prod-url',
                null,
                InputOption::VALUE_REQUIRED,
                'Absolute URL the export will be served from. Rewrites absolute links to it; ' .
                'when omitted, links are rewritten to root-relative paths instead (works for ' .
                'any domain the bundle ends up deployed to).'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('TAW Static Export');

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory. Is this theme installed inside a WordPress site (wp-content/themes/<theme>)?');
            return Command::FAILURE;
        }

        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }

        require $wpLoad;

        $outDir = rtrim((string) ($input->getOption('dir') ?? $this->themeDir . '/static-export'), '/');
        $prodUrl = $input->getOption('prod-url');
        $prodUrl = is_string($prodUrl) ? rtrim($prodUrl, '/') : null;

        if (!is_dir($outDir) && !mkdir($outDir, 0755, true) && !is_dir($outDir)) {
            $io->error("Could not create output directory: {$outDir}");
            return Command::FAILURE;
        }

        $homeUrl = untrailingslashit(home_url());

        $query = new \WP_Query([
            'post_type'      => self::POST_TYPES,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'no_found_rows'  => true,
            'orderby'        => 'ID',
            'order'          => 'ASC',
        ]);

        $io->text(sprintf('Found %d published page/post — fetching each over HTTP…', $query->post_count));

        $exported = [];
        $failed   = [];

        foreach ($query->posts as $post) {
            $permalink = get_permalink($post);

            if ($permalink === false) {
                $failed[] = [$post->post_type . ' #' . $post->ID, 'get_permalink() returned false'];
                continue;
            }

            $response = wp_remote_get($permalink, [
                'timeout' => 30,
                'headers' => ['X-TAW-Static-Export' => '1'],
            ]);

            if (is_wp_error($response)) {
                $failed[] = [$permalink, $response->get_error_message()];
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);
            if ($code !== 200) {
                $failed[] = [$permalink, "HTTP {$code}"];
                continue;
            }

            $html = wp_remote_retrieve_body($response);
            $html = $this->rewriteUrls($html, $homeUrl, $prodUrl);

            $relPath   = $this->relativePathFor($permalink, $homeUrl);
            $targetDir = $relPath === '' ? $outDir : $outDir . '/' . $relPath;

            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
                $failed[] = [$permalink, "Could not create directory: {$targetDir}"];
                continue;
            }

            file_put_contents($targetDir . '/index.html', $html);
            $exported[] = [$post->post_type, get_the_title($post), $relPath === '' ? '/' : "/{$relPath}/"];
        }

        // --- Static assets ---
        $distCopied    = $this->copyIfExists($this->themeDir . '/dist', $outDir . '/dist');
        $uploadsSrc    = wp_get_upload_dir()['basedir'] ?? '';
        $uploadsCopied = $uploadsSrc !== '' && $this->copyIfExists($uploadsSrc, $outDir . '/wp-content/uploads');

        // --- Report ---
        if (!empty($exported)) {
            $io->table(['Type', 'Title', 'Path'], $exported);
        }

        if (!empty($failed)) {
            $io->section('Failed to export');
            $io->table(['URL', 'Reason'], $failed);
        }

        $io->section('Assets');
        $io->listing([
            $distCopied
                ? "Vite build copied → {$outDir}/dist"
                : "⚠ dist/ not found at {$this->themeDir}/dist — run `npm run build` first, then re-export",
            $uploadsCopied
                ? "Uploads copied → {$outDir}/wp-content/uploads"
                : '⚠ wp-content/uploads not found or empty — nothing copied',
        ]);

        $io->success(sprintf('Exported %d page(s) to %s', count($exported), $outDir));

        if (!empty($failed)) {
            $io->warning(sprintf('%d page(s) failed to export — see table above.', count($failed)));
        }

        if (!$distCopied) {
            $io->warning('Vite assets were not copied. The export will be missing CSS/JS until you run `npm run build` and re-export.');
        }

        return empty($failed) ? Command::SUCCESS : Command::FAILURE;
    }

    /**
     * Absolute → root-relative (default) or absolute → --prod-url.
     * Handles the plain URL and its JSON-escaped form (schema.org blocks,
     * inline wp_localize_script data, etc. commonly escape the slash).
     */
    private function rewriteUrls(string $html, string $homeUrl, ?string $prodUrl): string
    {
        $replacement = $prodUrl ?? '';

        $html = str_replace($homeUrl, $replacement, $html);
        $html = str_replace(str_replace('/', '\/', $homeUrl), str_replace('/', '\/', $replacement), $html);

        return $html;
    }

    /**
     * Path of a permalink relative to the site root. Empty string means
     * the site's front page — callers write that to <dir>/index.html
     * instead of nesting it under a slug directory.
     */
    private function relativePathFor(string $permalink, string $homeUrl): string
    {
        $path     = trim((string) parse_url($permalink, PHP_URL_PATH), '/');
        $homePath = trim((string) parse_url($homeUrl, PHP_URL_PATH), '/');

        if ($homePath !== '' && str_starts_with($path, $homePath)) {
            $path = trim(substr($path, strlen($homePath)), '/');
        }

        return $path;
    }

    private function copyIfExists(string $src, string $dest): bool
    {
        if (!is_dir($src)) {
            return false;
        }

        $this->copyDir($src, $dest);
        return true;
    }

    private function copyDir(string $src, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        foreach (new \DirectoryIterator($src) as $item) {
            if ($item->isDot()) {
                continue;
            }

            $destPath = $dest . '/' . $item->getFilename();

            if ($item->isDir()) {
                $this->copyDir($item->getPathname(), $destPath);
            } else {
                copy($item->getPathname(), $destPath);
            }
        }
    }
}
