<?php

declare(strict_types=1);

namespace TAW\CLI;

use Composer\InstalledVersions;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Block\BlockRegistry;
use TAW\Core\Form\Form;
use TAW\Core\Metabox\Metabox;

/**
 * Live introspection of the current TAW site: registered blocks and their
 * metabox field schemas, registered forms, taw/core version, and a couple
 * of well-known scaffold flags (MetaboxOrder).
 *
 * Unlike make:block/export:block/import:block, this one needs WordPress
 * fully booted — the data it reports (registered blocks, forms, field
 * configs) only exists once `after_setup_theme` / `init` have fired.
 * Locates and loads `wp-load.php` itself via WpLoader, same as
 * FieldsGetCommand/FieldsSetCommand.
 *
 * PORTABILITY NOTE: same pattern as MakeBlockCommand — this class lives in
 * vendor/taw/core/ and receives the theme root via constructor injection.
 * It does not know or assume where WordPress itself lives beyond walking
 * up from the theme directory to find wp-load.php.
 */
class InspectCommand extends Command
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
            ->setName('inspect')
            ->setDescription('Report the live, current state of this TAW site: registered blocks, metabox fields, forms, and framework version')
            ->setHelp(<<<'HELP'
                Boots WordPress just enough to read the current registry — the same
                data agents would otherwise have to reconstruct by grepping PHP source.

                Examples:
                  <info>php bin/taw inspect</info>              human-readable summary
                  <info>php bin/taw inspect --json</info>       machine-readable JSON (for AI agents / scripts)
                HELP)
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a formatted summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');

        $wpLoad = WpLoader::locate($this->themeDir);

        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory. Is this theme installed inside a WordPress site (wp-content/themes/<theme>)?');
            return Command::FAILURE;
        }

        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }

        // Loading wp-load.php runs the full WP bootstrap, including the active
        // theme's functions.php (WP core does this itself) — which is what
        // fires after_setup_theme (BlockLoader::loadAll) and init (Form::register
        // calls inside each block's boot()). No separate action-firing needed.
        require $wpLoad;

        $report = [
            'taw_core_version' => InstalledVersions::isInstalled('taw/core')
                ? InstalledVersions::getPrettyVersion('taw/core')
                : null,
            'theme_dir' => $this->themeDir,
            'metabox_order_locked' => $this->detectMetaboxOrderLock(),
            'blocks' => $this->collectBlocks(),
            'forms' => $this->collectForms(),
        ];

        if ($asJson) {
            $output->writeln((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $this->renderHuman($io, $report);

        return Command::SUCCESS;
    }

    /**
     * Best-effort static check: is MetaboxOrder locking active for this site?
     * Intentionally a source-text check, not a runtime one — there's no
     * runtime flag on MetaboxOrder itself to query.
     *
     * Two ways this can be true:
     *   1. functions.php calls Theme::bootstrapFullSite() — which always
     *      calls MetaboxOrder::lockFromTemplate() internally as of taw/core
     *      v1.16.63, regardless of what's in functions.php itself.
     *   2. functions.php (pre-bootstrapFullSite theme, or a site that
     *      customized it) directly calls MetaboxOrder::lockFromTemplate()
     *      or MetaboxOrder::lock() itself.
     */
    private function detectMetaboxOrderLock(): bool
    {
        $functionsPhp = $this->themeDir . '/functions.php';

        if (!file_exists($functionsPhp)) {
            return false;
        }

        $contents = file_get_contents($functionsPhp) ?: '';

        if (preg_match('/Theme::bootstrapFullSite\s*\(/', $contents)) {
            return true;
        }

        return (bool) preg_match('/MetaboxOrder::(lockFromTemplate|lock)\s*\(/', $contents);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectBlocks(): array
    {
        $fieldRegistry = Metabox::getFieldRegistry();

        // Group the flat field registry by block_id so each block reports
        // only its own fields.
        $fieldsByBlock = [];
        foreach ($fieldRegistry as $fieldId => $field) {
            $blockId = $field['block_id'] ?? '(unassigned)';
            $fieldsByBlock[$blockId][] = [
                'id' => $fieldId,
                'type' => $field['type'] ?? 'text',
                'label' => $field['label'] ?? null,
                'required' => $field['required'] ?? false,
                'metabox_id' => $field['metabox_id'] ?? null,
                'metabox_title' => $field['metabox_title'] ?? null,
            ];
        }

        $blocks = [];

        foreach (BlockRegistry::getAll() as $id => $block) {
            $blocks[] = [
                'id' => $id,
                'class' => get_class($block),
                'variation' => method_exists($block, 'getVariation') ? $block->getVariation() : '',
                'fields' => $fieldsByBlock[$id] ?? [],
            ];
        }

        return $blocks;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectForms(): array
    {
        $forms = [];

        foreach (Form::getAll() as $id => $form) {
            $config = $form->getConfig();
            $fields = $config['fields'] ?? [];

            // Multi-step forms nest fields under 'steps' instead of a flat 'fields' key.
            if (empty($fields) && !empty($config['steps'])) {
                foreach ($config['steps'] as $step) {
                    foreach ($step['fields'] ?? [] as $field) {
                        $fields[] = $field;
                    }
                }
            }

            $forms[] = [
                'id' => $id,
                'multi_step' => !empty($config['steps']),
                'field_count' => count($fields),
                'field_ids' => array_values(array_filter(array_map(
                    static fn(array $f): ?string => $f['id'] ?? null,
                    $fields
                ))),
            ];
        }

        return $forms;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function renderHuman(SymfonyStyle $io, array $report): void
    {
        $io->title('TAW Site Inspection');

        $io->definitionList(
            ['taw/core version' => $report['taw_core_version'] ?? '(not installed via composer?)'],
            ['MetaboxOrder locked' => $report['metabox_order_locked'] ? 'yes' : 'no'],
        );

        $io->section('Blocks (' . count($report['blocks']) . ')');

        if (empty($report['blocks'])) {
            $io->text('No blocks registered.');
        } else {
            $rows = [];
            foreach ($report['blocks'] as $block) {
                $fieldSummary = empty($block['fields'])
                    ? '(no metabox fields)'
                    : implode(', ', array_map(
                        static fn(array $f): string => $f['id'] . ':' . $f['type'],
                        array_slice($block['fields'], 0, 6)
                    )) . (count($block['fields']) > 6 ? ', …' : '');

                $rows[] = [$block['id'], $block['class'], $block['variation'] ?: '—', $fieldSummary];
            }
            $io->table(['ID', 'Class', 'Variation', 'Fields'], $rows);
        }

        $io->section('Forms (' . count($report['forms']) . ')');

        if (empty($report['forms'])) {
            $io->text('No forms registered.');
        } else {
            $rows = [];
            foreach ($report['forms'] as $form) {
                $rows[] = [
                    $form['id'],
                    $form['multi_step'] ? 'yes' : 'no',
                    (string) $form['field_count'],
                    implode(', ', $form['field_ids']),
                ];
            }
            $io->table(['ID', 'Multi-step', 'Fields', 'Field IDs'], $rows);
        }
    }
}
