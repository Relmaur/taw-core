<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TAW\Core\Metabox\Metabox;

/**
 * Read a single Metabox/OptionsPage field's current value, formatted
 * according to its registered type — the read half of the same primitive
 * VisualEditorEndpoint uses to save fields, exposed as a CLI command so
 * agents don't have to hand-decode repeater JSON or post_select arrays
 * themselves.
 *
 * Boots WordPress (same pattern as InspectCommand) — field configs and
 * post_meta only exist once WordPress is loaded.
 */
class FieldsGetCommand extends Command
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
            ->setName('fields:get')
            ->setDescription("Read a Metabox/OptionsPage field's current value for a post, decoded according to its type")
            ->setHelp(<<<'HELP'
                Looks the field up in the live Metabox field registry (the same one
                `php bin/taw inspect --json` reports) to know its type, then decodes
                the stored value accordingly — repeaters and post_select come back as
                arrays, checkboxes as booleans, everything else as its raw value.

                Examples:
                  <info>php bin/taw fields:get 42 hero_heading</info>
                  <info>php bin/taw fields:get 42 team_members --json</info>
                HELP)
            ->addArgument('post_id', InputArgument::REQUIRED, 'Post ID the field is stored against')
            ->addArgument('field_id', InputArgument::REQUIRED, "Field ID, without the meta key prefix (e.g. 'hero_heading', or 'hero_cta_text' for a group sub-field)")
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON instead of a formatted summary');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $asJson = (bool) $input->getOption('json');
        $postId = (int) $input->getArgument('post_id');
        $fieldId = (string) $input->getArgument('field_id');

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $io->error('Could not locate wp-load.php by walking up from the theme directory.');
            return Command::FAILURE;
        }

        if (!defined('WP_USE_THEMES')) {
            define('WP_USE_THEMES', false);
        }
        require $wpLoad;

        if (!get_post($postId)) {
            $io->error("No post found with ID {$postId}.");
            return Command::FAILURE;
        }

        $fieldConfig = Metabox::get_field_config($fieldId);
        if ($fieldConfig === null) {
            $io->error("Unknown field: '{$fieldId}'. Run 'php bin/taw inspect --json' to see registered field IDs per block.");
            return Command::FAILURE;
        }

        $prefix = $fieldConfig['prefix'] ?? '_taw_';
        $type = $fieldConfig['type'] ?? 'text';
        $value = $this->readValue($postId, $fieldId, $type, $prefix);

        if ($asJson) {
            $output->writeln((string) json_encode([
                'post_id' => $postId,
                'field_id' => $fieldId,
                'type' => $type,
                'meta_key' => $prefix . $fieldId,
                'value' => $value,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->definitionList(
            ['Post ID' => (string) $postId],
            ['Field' => $fieldId . ' (' . $type . ')'],
            ['Meta key' => $prefix . $fieldId],
        );
        $io->section('Value');
        $io->text(is_string($value) ? $value : (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }

    /**
     * Dispatch to the type-appropriate static getter on Metabox — mirrors
     * how templates already read these fields via getData(), so a value
     * read here is exactly what a block's own template would see.
     */
    private function readValue(int $postId, string $fieldId, string $type, string $prefix): mixed
    {
        return match ($type) {
            'repeater' => Metabox::get_repeater($postId, $fieldId, $prefix),
            'checkbox' => Metabox::get_bool($postId, $fieldId, $prefix),
            'post_select' => Metabox::get_posts($postId, $fieldId, $prefix),
            'files' => json_decode((string) Metabox::get($postId, $fieldId, $prefix), true) ?: [],
            'image' => (int) Metabox::get($postId, $fieldId, $prefix),
            default => Metabox::get($postId, $fieldId, $prefix),
        };
    }
}
