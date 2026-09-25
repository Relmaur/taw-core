<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Schema\Definition\Fieldset;
use TAW\Core\Schema\Definition\PostType;
use TAW\Core\Schema\Definition\Taxonomy;
use TAW\Core\Schema\JsonLoader;
use TAW\Core\Schema\Source;

/**
 * Validate taw-schema/*.json definition files — WITHOUT booting WordPress,
 * so it runs in CI, in a pre-commit hook, or on a machine with no database.
 *
 * Errors (the command fails): invalid JSON, anything the format forbids
 * (unknown keys, wrong types, unknown field types, bad keys — each with a
 * JSON pointer), reserved or over-long WordPress names.
 *
 * Warnings (the command still passes): the same entity defined in two
 * files, a fieldset/taxonomy pointing at a post type these files don't
 * define, and a fieldset's `term:<taxonomy>` target naming a taxonomy they
 * don't define. That one is only a warning because the post type may be
 * registered in PHP, by WordPress itself, or — for fieldsets — be a page
 * slug or template filename, none of which is visible without WordPress.
 */
class SchemaValidateCommand extends Command
{
    /** Post types WordPress always has; targeting these is never suspicious. */
    private const CORE_POST_TYPES = ['post', 'page', 'attachment'];

    private const CORE_TAXONOMIES = ['category', 'post_tag', 'post_format', 'link_category', 'wp_pattern_category'];

    public function __construct(private readonly string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('schema:validate')
            ->setDescription('Validate taw-schema/*.json definition files (no WordPress needed)')
            ->setHelp(<<<'HELP'
                Checks every *.json file in the given folders (and their direct
                subfolders) against the TAW schema format — the same rules
                WordPress applies at runtime, where an invalid file is skipped.

                With no arguments, checks this theme's taw-schema/ folder.

                Examples:
                  <info>php bin/taw schema:validate</info>
                  <info>php bin/taw schema:validate taw-schema/post-types/book.json</info>
                  <info>php bin/taw schema:validate ../../taw-schema --json</info>
                HELP)
            ->addArgument('paths', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'Files or folders to check (default: <theme>/taw-schema)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

        /** @var list<string> $paths */
        $paths = $input->getArgument('paths');
        if ($paths === []) {
            $paths = [$this->themeDir . '/taw-schema'];
        }

        $files = $this->collectFiles($paths);
        $report = $this->validate($files);

        if ($asJson) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $report['valid'] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($files === []) {
            $io->warning('No schema files found in: ' . implode(', ', $paths));

            return Command::SUCCESS;
        }

        foreach ($report['files'] as $file) {
            if ($file['errors'] === []) {
                $io->writeln("<info>✔</info> {$file['path']}");
                continue;
            }
            $io->writeln("<error>✘</error> {$file['path']}");
            foreach ($file['errors'] as $error) {
                $io->writeln("    {$error}");
            }
        }

        foreach ($report['warnings'] as $warning) {
            $io->warning($warning);
        }

        $invalid = count(array_filter($report['files'], static fn (array $f): bool => $f['errors'] !== []));
        if ($invalid > 0) {
            $io->error(sprintf('%d of %d file(s) invalid. WordPress skips invalid files at runtime.', $invalid, count($files)));

            return Command::FAILURE;
        }

        $io->success(sprintf('%d file(s) valid.', count($files)));

        return Command::SUCCESS;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function collectFiles(array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            if (is_file($path)) {
                $files[] = realpath($path) ?: $path;
                continue;
            }
            foreach (JsonLoader::findFiles([$path => Source::RANK_PARENT_THEME]) as $found) {
                $files[] = $found['path'];
            }
        }

        return array_values(array_unique($files));
    }

    /**
     * @param list<string> $files
     * @return array{valid: bool, files: list<array{path: string, errors: list<string>}>, warnings: list<string>}
     */
    private function validate(array $files): array
    {
        $results = [];
        $definitions = [];   // qualified key => first file
        $warnings = [];
        $postTypes = self::CORE_POST_TYPES;
        $targets = [];       // [file, what, post type]
        $taxonomies = self::CORE_TAXONOMIES;
        $termTargets = [];   // [file, what, taxonomy]

        foreach ($files as $path) {
            $result = JsonLoader::readFile($path);
            $results[] = ['path' => $path, 'errors' => $result['errors']];

            if ($result['data'] === null) {
                continue;
            }

            $definition = JsonLoader::toDefinition($result['data']);
            $qualifiedKey = $definition->qualifiedKey();

            if (isset($definitions[$qualifiedKey])) {
                $warnings[] = sprintf('"%s" is defined in both %s and %s; at runtime the later one wins (with a notice unless it sets "override": true).', $qualifiedKey, $definitions[$qualifiedKey], $path);
            } else {
                $definitions[$qualifiedKey] = $path;
            }

            if ($definition instanceof PostType) {
                $postTypes[] = $definition->key();
            } elseif ($definition instanceof Taxonomy) {
                $taxonomies[] = $definition->key();
                foreach ($definition->objectTypes() as $type) {
                    $targets[] = [$path, sprintf('taxonomy "%s"', $definition->key()), $type];
                }
            } elseif ($definition instanceof Fieldset) {
                foreach ($definition->taxonomies() as $taxonomy) {
                    $termTargets[] = [$path, sprintf('fieldset "%s"', $definition->key()), $taxonomy];
                }
                foreach ($definition->screens() as $screen) {
                    // Template filenames (page-about.php) are never post types; term targets are
                    // checked above, and "user" targets the user screens.
                    if (!str_ends_with($screen, '.php') && !str_starts_with($screen, 'term:') && $screen !== 'user') {
                        $targets[] = [$path, sprintf('fieldset "%s"', $definition->key()), $screen];
                    }
                }
            }
        }

        foreach ($targets as [$path, $what, $type]) {
            if (!in_array($type, $postTypes, true)) {
                $warnings[] = sprintf('%s (%s) targets "%s", which these files don\'t define as a post type. Fine if it\'s registered in PHP or is a page slug.', $what, $path, $type);
            }
        }

        foreach ($termTargets as [$path, $what, $taxonomy]) {
            if (!in_array($taxonomy, $taxonomies, true)) {
                $warnings[] = sprintf('%s (%s) targets terms of "%s", which these files don\'t define as a taxonomy. Fine if it\'s registered in PHP or by a plugin.', $what, $path, $taxonomy);
            }
        }

        $valid = array_filter($results, static fn (array $r): bool => $r['errors'] !== []) === [];

        return ['valid' => $valid, 'files' => $results, 'warnings' => $warnings];
    }
}
