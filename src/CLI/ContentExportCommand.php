<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Content\Exporter;

/**
 * `bin/taw content:export` — write a portable content snapshot
 * (posts + `_taw_*` fields, `_taw_*` options, terms, referenced media) to
 * a JSON file for review, transfer, or agent-mediated transformation.
 *
 * The CLI face of {@see \TAW\Core\Content\Exporter}. Boots WordPress the
 * same way `inspect` / `seo:extract` do.
 */
class ContentExportCommand extends Command
{
    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('content:export')
            ->setDescription('Export a portable JSON snapshot of this site\'s content, TAW fields, options, terms and referenced media')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>php bin/taw content:export</info>
                  <info>php bin/taw content:export --output=/tmp/site.json</info>
                  <info>php bin/taw content:export --types=page,post --since=2025-01-01</info>
                  <info>php bin/taw content:export --posts=12,about --no-media</info>
                  <info>php bin/taw content:export --migrate --output=/tmp/site.json</info>   full site state

                --migrate is a shorthand for --with-users --with-settings --all-media
                --include-drafts (it does NOT enable --with-user-passwords or
                --with-comments — those stay explicit).
                HELP)
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'File to write (default: .taw/content-export.json)')
            ->addOption('types', null, InputOption::VALUE_REQUIRED, 'Comma-separated post types to limit the export to')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only posts dated on or after this date (Y-m-d)')
            ->addOption('posts', null, InputOption::VALUE_REQUIRED, 'Comma-separated post IDs or slugs to limit the export to')
            ->addOption('no-media', null, InputOption::VALUE_NONE, 'Omit the media[] section')
            ->addOption('all-media', null, InputOption::VALUE_NONE, 'Also export attachments not referenced by any exported post/field')
            ->addOption('include-drafts', null, InputOption::VALUE_NONE, 'Include draft / pending posts (excluded by default)')
            ->addOption('with-users', null, InputOption::VALUE_NONE, 'Export a users[] section (no password hashes)')
            ->addOption('with-user-passwords', null, InputOption::VALUE_NONE, 'Include portable password hashes in users[] (implies --with-users)')
            ->addOption('with-comments', null, InputOption::VALUE_NONE, 'Export comments on the exported posts')
            ->addOption('with-settings', null, InputOption::VALUE_NONE, 'Export the environment-settings option allowlist')
            ->addOption('migrate', null, InputOption::VALUE_NONE, '= --with-users --with-settings --all-media --include-drafts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $outputPath = $input->getOption('output');
        $outputPath = is_string($outputPath) ? $outputPath : '.taw/content-export.json';

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory.');
            return Command::FAILURE;
        }
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        WpLoader::autoConfigureLocalSocket($this->themeDir);
        require $wpLoad;

        $migrate = (bool) $input->getOption('migrate');

        $scope = [
            'include_media'          => !$input->getOption('no-media'),
            'all_media'              => $migrate || (bool) $input->getOption('all-media'),
            'include_drafts'         => $migrate || (bool) $input->getOption('include-drafts'),
            'include_users'          => $migrate || (bool) $input->getOption('with-users') || (bool) $input->getOption('with-user-passwords'),
            'include_user_passwords' => (bool) $input->getOption('with-user-passwords'),
            'include_comments'       => (bool) $input->getOption('with-comments'),
            'include_settings'       => $migrate || (bool) $input->getOption('with-settings'),
        ];
        if (is_string($input->getOption('types')) && $input->getOption('types') !== '') {
            $scope['types'] = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('types')))));
        }
        if (is_string($input->getOption('since')) && $input->getOption('since') !== '') {
            $scope['since'] = (string) $input->getOption('since');
        }
        if (is_string($input->getOption('posts')) && $input->getOption('posts') !== '') {
            $scope['posts'] = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('posts')))));
        }

        $exporter = new Exporter();
        $snapshot = $exporter->snapshot($scope);

        $dir = dirname($outputPath);
        if ($dir !== '.' && !is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            $io->error("Could not create output directory: {$dir}");
            return Command::FAILURE;
        }

        file_put_contents(
            $outputPath,
            (string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        $io->success("Wrote {$outputPath}");
        $io->definitionList(
            ['Schema' => Exporter::SCHEMA_VERSION],
            ['Posts' => (string) count($snapshot['posts'])],
            ['Options' => (string) count($snapshot['options'])],
            ['Terms' => (string) array_sum(array_map('count', $snapshot['terms']))],
            ['Media' => (string) count($snapshot['media'] ?? [])],
            ['Users' => (string) count($snapshot['users'] ?? [])],
            ['Comments' => (string) count($snapshot['comments'] ?? [])],
        );

        foreach ($exporter->warnings() as $warning) {
            $io->warning($warning);
        }

        return Command::SUCCESS;
    }
}
