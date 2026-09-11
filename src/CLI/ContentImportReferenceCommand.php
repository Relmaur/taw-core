<?php

declare(strict_types=1);

namespace TAW\CLI;

use PDO;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Rag\Reference\ReferenceImporter;
use TAW\Core\Rag\Reference\ReferenceSchema;
use TAW\Core\Rag\Reference\SchemaManager;

/**
 * `bin/taw content:import-reference <bible|catechism> <file> [--db=path] [--dry-run] [--yes] [--json]`
 *
 * Generic importer for the two reference SQLite DBs (Bible, Catechism of
 * Trent) behind the RAG chatbot's `lookup_bible`/`lookup_catechism` tools.
 * Mandatory dry-run, same shape as `content:import`: without `--yes` this
 * only reports what would change.
 *
 * With `--db`, runs on a bare PDO connection to an arbitrary SQLite file —
 * WordPress is never booted, which is what makes this testable against
 * fixtures. Without `--db`, boots WordPress via {@see WpLoader} and writes
 * to the site's real reference DB under `wp-content/uploads/taw-private/rag/`.
 */
class ContentImportReferenceCommand extends Command
{
    private const FILENAMES = [
        'bible' => 'bible_straubinger.sqlite',
        'catechism' => 'catechism_trent.sqlite',
    ];

    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('content:import-reference')
            ->setDescription('Import verses/entries into a reference SQLite DB (bible or catechism) — dry-run by default, --yes to apply')
            ->setHelp(<<<'HELP'
                Examples:
                  <info>php bin/taw content:import-reference bible verses.csv</info>                         dry-run against the site's live DB
                  <info>php bin/taw content:import-reference bible verses.csv --yes</info>                    apply it
                  <info>php bin/taw content:import-reference bible fixture.csv --db=/tmp/test.sqlite --yes</info>  apply against an arbitrary file, no WordPress needed
                HELP)
            ->addArgument('schema', InputArgument::REQUIRED, 'Which reference schema: bible | catechism')
            ->addArgument('file', InputArgument::REQUIRED, 'Path to a .csv or .json file of rows')
            ->addOption('db', null, InputOption::VALUE_REQUIRED, 'Import into this SQLite file instead of the site\'s reference DB (no WordPress boot required)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Force dry-run even if --yes is given')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'Apply the changes (otherwise the command only previews them)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output the report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

        $schemaName = (string) $input->getArgument('schema');
        if (!isset(self::FILENAMES[$schemaName])) {
            $io->error("Unknown schema '{$schemaName}'. Use 'bible' or 'catechism'.");
            return Command::FAILURE;
        }
        $schema = ReferenceSchema::forName($schemaName);

        $file = (string) $input->getArgument('file');
        if (!is_file($file) || !is_readable($file)) {
            $io->error("Cannot read file: {$file}");
            return Command::FAILURE;
        }

        $dbPath = $this->resolveDbPath($input, $schemaName, $io);
        if ($dbPath === null) {
            return Command::FAILURE;
        }

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        SchemaManager::ensureTable($pdo, $schema);

        $importer = new ReferenceImporter();

        try {
            $rows = $importer->parse($file, $schema);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $apply = $input->getOption('yes') && !$input->getOption('dry-run');

        $report = $apply
            ? $importer->apply($pdo, $schema, $rows)
            : $importer->plan($pdo, $schema, $rows);

        if ($asJson) {
            $output->writeln((string) json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->definitionList(
            ['Rows read' => (string) count($rows)],
            ['Would insert / Inserted' => (string) $report->inserted],
            ['Would update / Updated' => (string) $report->updated],
            ['Skipped (unchanged or invalid)' => (string) $report->skipped],
        );
        foreach ($report->warnings as $warning) {
            $io->warning($warning);
        }

        if (!$apply) {
            $io->note('Dry run — nothing was written. Re-run with --yes to apply.');
        } else {
            $io->success("Applied to {$dbPath}");
        }

        return Command::SUCCESS;
    }

    private function resolveDbPath(InputInterface $input, string $schemaName, SymfonyStyle $io): ?string
    {
        $explicit = $input->getOption('db');
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory. Pass --db=<path> to import without WordPress.');
            return null;
        }
        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        WpLoader::autoConfigureLocalSocket($this->themeDir);
        require $wpLoad;

        \TAW\Core\Rag\Storage::ensureProtectedDir(\TAW\Core\Rag\Storage::dir());

        return \TAW\Core\Rag\Storage::dbPath(self::FILENAMES[$schemaName]);
    }
}
