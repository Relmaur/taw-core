<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Question\ChoiceQuestion;

/**
 * Scaffold a new TAW block.
 *
 * PORTABILITY NOTE:
 * -----------------
 * This command lives in vendor/taw/core/ but needs to create files
 * in the THEME directory. It receives the theme path via constructor
 * injection from bin/taw — this is what makes it portable.
 *
 * Before (monolith):   $this->themeDir = dirname(__DIR__, 2);
 * After  (package):    $this->themeDir = $themeDir; // injected
 */
class MakeBlockCommand extends Command
{
    private string $themeDir;

    /**
     * @param string $themeDir  Absolute path to the theme root.
     *                          Injected by bin/taw — the CLI knows
     *                          where the theme is, the command doesn't.
     */
    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('make:block')
            ->setDescription('Scaffold a new TAW block')
            ->setHelp(<<<'HELP'
                Creates a new block with the proper folder structure, class file,
                and template. The block is immediately auto-discovered by BlockLoader.

                Examples:
                  <info>php bin/taw make:block Hero --type=meta --with-style</info>
                  <info>php bin/taw make:block Badge --type=ui --group=ui</info>
                  <info>php bin/taw make:block Hero --group=sections/landing</info>
                  <info>php bin/taw make:block PricingTable</info> (interactive mode)
                HELP)
            ->addArgument(
                'name',
                InputArgument::REQUIRED,
                'Block name in PascalCase (e.g., Hero, PricingTable)'
            )
            ->addOption('type', 't', InputOption::VALUE_REQUIRED, 'Block type: meta or ui')
            ->addOption('group', 'g', InputOption::VALUE_REQUIRED, 'Group subfolder path (e.g. sections, ui/cards)')
            ->addOption('with-style', 's', InputOption::VALUE_NONE, 'Include a style.scss file')
            ->addOption('with-script', 'j', InputOption::VALUE_NONE, 'Include a script.js file')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing block');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('TAW Block Scaffolder');

        // --- Validate name ---
        $name = $input->getArgument('name');

        if (!preg_match('/^[A-Z][a-zA-Z0-9]+$/', $name)) {
            $io->error([
                "Invalid block name: '{$name}'",
                'Block names must be PascalCase (e.g., Hero, PricingTable, BlogGrid).',
            ]);
            return Command::INVALID;
        }

        // --- Validate group ---
        $group = $input->getOption('group');

        if ($group !== null) {
            $group = trim($group, '/');
            if (!preg_match('#^[a-zA-Z_][a-zA-Z0-9_]*(\/[a-zA-Z_][a-zA-Z0-9_]*)*$#', $group)) {
                $io->error([
                    "Invalid group path: '{$group}'",
                    'Group paths must use letters, digits, underscores, and forward slashes (e.g. sections, ui/cards).',
                ]);
                return Command::INVALID;
            }
        }

        // --- Determine type ---
        $type = $input->getOption('type');

        if ($type === null) {
            $helper = $this->getHelper('question');
            $question = new ChoiceQuestion(
                'What type of block? (default: meta)',
                ['meta' => 'MetaBlock — owns data via metaboxes', 'ui' => 'Block — presentational, receives props'],
                'meta'
            );
            $type = $helper->ask($input, $output, $question);
        }

        if (!in_array($type, ['meta', 'ui'])) {
            $io->error("Invalid type '{$type}'. Must be 'meta' or 'ui'.");
            return Command::INVALID;
        }

        // --- Resolve paths and namespace ---
        $relPath   = $group ? "{$group}/{$name}" : $name;
        $namespace = 'TAW\\Blocks\\' . str_replace('/', '\\', $relPath);
        $blockDir  = $this->themeDir . '/Blocks/' . $relPath;
        $force     = $input->getOption('force');

        if (is_dir($blockDir) && !$force) {
            $io->error([
                "Block '{$name}' already exists at:",
                "Blocks/{$relPath}",
                'Use --force to overwrite.',
            ]);
            return Command::FAILURE;
        }

        // --- Create block ---
        $withStyle  = $input->getOption('with-style');
        $withScript = $input->getOption('with-script');
        $id = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));

        if (!is_dir($blockDir)) {
            mkdir($blockDir, 0755, true);
        }

        $createdFiles = [];

        // 1. Class file
        $classContent = $type === 'meta'
            ? $this->generateMetaBlockClass($name, $id, $namespace)
            : $this->generateUiBlockClass($name, $id, $namespace);
        file_put_contents($blockDir . '/' . $name . '.php', $classContent);
        $createdFiles[] = ['Class', "Blocks/{$relPath}/{$name}.php"];

        // 2. Template
        $templateContent = $type === 'meta'
            ? $this->generateMetaTemplate($name, $id)
            : $this->generateUiTemplate($name, $id);
        file_put_contents($blockDir . '/index.php', $templateContent);
        $createdFiles[] = ['Template', "Blocks/{$relPath}/index.php"];

        // 3. Optional style
        if ($withStyle) {
            $scss = <<<SCSS
            /**
             * {$name} Block Styles
             */

            .{$id} {

            }
            SCSS;
            file_put_contents($blockDir . '/style.scss', $scss);
            $createdFiles[] = ['Stylesheet', "Blocks/{$relPath}/style.scss"];
        }

        // 4. Optional script
        if ($withScript) {
            $js = <<<JS
            /**
             * {$name} Block Script
             */

            console.log('{$name} block initialized.');
            JS;
            file_put_contents($blockDir . '/script.js', $js);
            $createdFiles[] = ['Script', "Blocks/{$relPath}/script.js"];
        }

        // --- Success ---
        $io->success("Block '{$name}' created!");
        $io->table(['Asset', 'Path'], $createdFiles);

        $io->section('Next Steps');
        $io->listing([
            'Run <info>composer dump-autoload</info> to register the new class',
            $type === 'meta'
                ? "Add <info>BlockRegistry::queue('{$id}')</info> to your template"
                : "Use <info>(new {$name}())->render([...])</info> in any template",
        ]);

        return Command::SUCCESS;
    }

    // --- Template generators ---

    private function generateMetaBlockClass(string $name, string $id, string $namespace): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use TAW\Core\MetaBlock;
        use TAW\Core\Metabox\Metabox;

        class {$name} extends MetaBlock
        {
            protected string \$id = '{$id}';

            protected function registerMetaboxes(): void
            {
                new Metabox([
                    'id'     => 'taw_{$id}',
                    'title'  => __( '{$name} Section', 'taw-theme' ),
                    'screen' => 'page',
                    'fields' => [
                        [
                            'id'    => '{$id}_heading',
                            'label' => __( 'Heading', 'taw-theme' ),
                            'type'  => 'text',
                        ],
                    ],
                ]);
            }

            protected function getData(int \$postId): array
            {
                return [
                    'heading' => \$this->getMeta(\$postId, '{$id}_heading'),
                ];
            }
        }

        PHP;
    }

    private function generateUiBlockClass(string $name, string $id, string $namespace): string
    {
        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace {$namespace};

        use TAW\Core\Block;

        class {$name} extends Block
        {
            protected string \$id = '{$id}';

            protected function defaults(): array
            {
                return [
                    'text' => '',
                ];
            }
        }

        PHP;
    }

    private function generateMetaTemplate(string $name, string $id): string
    {
        return <<<PHP
        <?php
        /**
         * {$name} Block Template
         *
         * @var string \$heading
         */

        if (empty(\$heading)) return;
        ?>

        <section class="{$id}">
            <div class="container mx-auto px-4">
                <h2 class="text-3xl font-bold">
                    <?php echo esc_html(\$heading); ?>
                </h2>
            </div>
        </section>

        PHP;
    }

    private function generateUiTemplate(string $name, string $id): string
    {
        return <<<PHP
        <?php
        /**
         * {$name} Block Template
         *
         * @var string \$text
         */
        ?>

        <div class="{$id}">
            <?php echo esc_html(\$text); ?>
        </div>

        PHP;
    }
}
