<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use TAW\Update\Policy;

/**
 * `bin/taw policy`: the site's update policy (taw.json) in words — what an
 * update will change on its own, which settings are the site's and which are
 * defaults, and what's wrong with the file if anything is.
 */
final class PolicyCommand extends Command
{
    public function __construct(private string $themeDir)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('policy')
            ->setDescription("Show this site's update policy (taw.json): what an update changes on its own")
            ->setHelp(
                "Reads taw.json in the theme (the \"update\" settings) and says what each one means.\n" .
                "Settings the file leaves out take their defaults. An invalid file is listed with\n" .
                "its errors, and updates refuse to run until it's fixed.\n\n" .
                "  <info>vendor/bin/taw policy</info>          in words\n" .
                "  <info>vendor/bin/taw policy --json</info>   for taw-fleet and scripts\n" .
                "  <info>vendor/bin/taw policy --init</info>   write a starter taw.json with every default\n\n" .
                "Edit taw.json by hand; editors that read JSON schemas complete and check it."
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Machine-readable output')
            ->addOption('init', null, InputOption::VALUE_NONE, 'Write a starter taw.json (never overwrites one)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = rtrim($this->themeDir, '/') . '/' . Policy::FILE;
        if ($input->getOption('init')) {
            if (is_file($file)) {
                $output->writeln('<comment>' . Policy::FILE . ' already exists; edit it by hand.</comment>');

                return Command::FAILURE;
            }
            file_put_contents($file, Policy::starter());
            $output->writeln('<info>✓</info> Wrote ' . Policy::FILE . ' with every default. Change what you need; commit it with the theme.');

            return Command::SUCCESS;
        }

        $policy = Policy::load($this->themeDir);
        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'file' => $policy->hasFile() ? Policy::FILE : null,
                'valid' => $policy->valid(),
                'errors' => $policy->errors(),
                'policy' => $policy->toArray(),
                'from_file' => array_values(array_filter(array_keys(Policy::SETTINGS), fn (string $k) => $policy->isSet($k))),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $policy->valid() ? Command::SUCCESS : Command::FAILURE;
        }

        $output->writeln($policy->hasFile()
            ? 'Update policy from <info>' . Policy::FILE . '</info> (settings it leaves out are defaults):'
            : 'No ' . Policy::FILE . ' here, so updates follow the defaults (<info>vendor/bin/taw policy --init</info> writes one):');
        $output->writeln('');
        foreach (array_keys(Policy::SETTINGS) as $key) {
            $value = $policy->toArray()[$key];
            $shown = is_array($value) ? implode(', ', $value) : $value;
            $source = $policy->isSet($key) ? 'taw.json' : 'default';
            $output->writeln(sprintf('  <info>%-9s</info> %s  <comment>(%s)</comment>', $key, $shown, $source));
            $output->writeln('            ' . $policy->explain($key));
        }
        if (!$policy->valid()) {
            $output->writeln('');
            $output->writeln('<error>' . Policy::FILE . ' has problems; updates won\'t run until they\'re fixed:</error>');
            foreach ($policy->errors() as $error) {
                $output->writeln('  - ' . $error);
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
