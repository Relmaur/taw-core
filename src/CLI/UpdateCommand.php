<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TAW\Update\Policy;
use TAW\Update\ProcessShell;
use TAW\Update\Updater;

/**
 * `bin/taw update`: the whole update of this theme, as its taw.json says,
 * with no questions. See TAW\Update\Updater.
 */
final class UpdateCommand extends Command
{
    public function __construct(private string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('update')
            ->setDescription("Update this theme as its taw.json says: taw/core, framework files, migrations, checks, then a branch or pull request")
            ->setHelp(
                "On a new branch: updates taw/core within the policy's range, applies the framework files, runs the\n" .
                "migrations and the checks, commits, and delivers (taw.json \"deliver\": a pull request by default).\n" .
                "It never asks; it refuses to start on uncommitted changes. If a step fails, nothing is pushed: the work\n" .
                "stays on the branch and .taw/update-report.md says what failed and how to finish it by hand — the same\n" .
                "file you can give Claude (\"Fix with Claude\").\n\n" .
                "  <info>vendor/bin/taw update</info>                 do it\n" .
                "  <info>vendor/bin/taw update --plan</info>          what it would do, nothing changed\n" .
                "  <info>vendor/bin/taw update --no-deliver</info>    stop at the commit on the branch\n" .
                "  <info>vendor/bin/taw update --json</info>          the result as JSON (taw-fleet reads it)\n" .
                "  <info>--composer=\"php /path/composer.phar\"</info>  how to run Composer (default: composer; quote a part with spaces)"
            )
            ->addOption('plan', null, InputOption::VALUE_NONE, 'Say what it would do; change nothing')
            ->addOption('no-deliver', null, InputOption::VALUE_NONE, 'Commit on the branch; don\'t push or open a pull request')
            ->addOption('composer', null, InputOption::VALUE_REQUIRED, 'The command that runs Composer', 'composer')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable result');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $policy = Policy::load($this->themeDir);
        if ($input->getOption('plan')) {
            if ($input->getOption('json')) {
                $output->writeln((string) json_encode([
                    'valid' => $policy->valid(),
                    'errors' => $policy->errors(),
                    'policy' => $policy->toArray(),
                    'would' => array_combine(array_keys(Policy::SETTINGS), array_map(fn (string $k) => $policy->explain($k), array_keys(Policy::SETTINGS))),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

                return $policy->valid() ? Command::SUCCESS : Command::FAILURE;
            }
            $output->writeln('An update of ' . basename($this->themeDir) . ' would, on a new branch:');
            foreach (array_keys(Policy::SETTINGS) as $key) {
                $output->writeln('  <info>' . $key . '</info>: ' . $policy->explain($key));
            }
            if (!$policy->valid()) {
                $output->writeln('<error>taw.json has problems, so it would refuse to start:</error> ' . implode('; ', $policy->errors()));
            }

            return $policy->valid() ? Command::SUCCESS : Command::FAILURE;
        }

        $composer = Updater::words((string) $input->getOption('composer')) ?: ['composer'];
        // Progress as it goes: on stderr with --json, so stdout stays one JSON document.
        $progress = $input->getOption('json') && $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $say = static function (string $line) use ($progress): void {
            $progress->writeln($line, OutputInterface::OUTPUT_RAW);
        };
        $result = (new Updater($this->themeDir, new ProcessShell(), $composer, null, PHP_BINARY, $say))->run(!$input->getOption('no-deliver'));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $output->writeln((string) file_get_contents($this->themeDir . '/' . Updater::REPORT . '.md'));
            $output->writeln('<comment>This report: ' . Updater::REPORT . '.md</comment>');
        }

        return in_array($result['status'], ['updated', 'up-to-date'], true) ? Command::SUCCESS : Command::FAILURE;
    }
}
