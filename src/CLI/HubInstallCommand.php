<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Install (or update) the `taw-hub-companion` plugin on this site — the
 * signed `wp-json/taw-hub/v1/` receiver the TAW Hub (`Relmaur/taw-hub`)
 * talks to.
 *
 * It's a standalone plugin, deliberately NOT bundled with the theme: only
 * sites that join a managed fleet need it, and it's a security boundary with
 * its own release cadence. This command just fetches it into
 * `wp-content/plugins/` and prints the remaining (deliberate) steps —
 * wiring `TAW_HUB_PUBLIC_KEY` into `wp-config.php` and registering the
 * site's key with the Hub. The `hub-connect` skill orchestrates those
 * interactively.
 *
 *   php bin/taw hub:install               # clone into wp-content/plugins/
 *   php bin/taw hub:install --activate    # …and `wp plugin activate` it
 *   php bin/taw hub:install --update      # git pull an existing checkout
 */
class HubInstallCommand extends Command
{
    private const REPO   = 'https://github.com/Relmaur/taw-hub-companion.git';
    private const PLUGIN  = 'taw-hub-companion';

    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('hub:install')
            ->setDescription('Install/update the taw-hub-companion plugin (fleet management receiver)')
            ->addOption('activate', 'a', InputOption::VALUE_NONE, 'Also `wp plugin activate` it')
            ->addOption('update', 'u', InputOption::VALUE_NONE, 'git pull an existing checkout instead of skipping')
            ->addOption('dir', null, InputOption::VALUE_REQUIRED, 'Plugins directory (default: resolved from the theme path)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print what would happen, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $git = (new ExecutableFinder())->find('git');
        if ($git === null) {
            $io->error('git not found on PATH — needed to fetch the plugin.');

            return Command::FAILURE;
        }

        $dirOption = $input->getOption('dir');
        $pluginsDir = is_string($dirOption) && $dirOption !== ''
            ? rtrim($dirOption, '/')
            : self::resolvePluginsDir($this->themeDir);

        if ($pluginsDir === null) {
            $io->error('Could not locate wp-content/plugins — pass --dir=<path>.');

            return Command::FAILURE;
        }

        $target  = $pluginsDir . '/' . self::PLUGIN;
        $dryRun  = (bool) $input->getOption('dry-run');
        $update  = (bool) $input->getOption('update');
        $exists  = is_dir($target . '/.git');

        if ($exists && !$update) {
            $io->warning("{$target} already exists. Re-run with --update to `git pull` it.");
        } elseif ($exists) {
            $io->section('Updating');
            if (!$this->runOrPrint($io, $dryRun, [$git, '-C', $target, 'pull', '--ff-only'], $pluginsDir)) {
                return Command::FAILURE;
            }
        } else {
            $io->section('Fetching');
            if (!is_dir($pluginsDir)) {
                $io->error("Plugins directory does not exist: {$pluginsDir}");

                return Command::FAILURE;
            }
            if (!$this->runOrPrint($io, $dryRun, [$git, 'clone', '--depth', '1', self::REPO, $target], $pluginsDir)) {
                return Command::FAILURE;
            }
        }

        // Optional — the plugin has an autoload fallback, so this only
        // upgrades it to the real Composer autoloader.
        $composer = (new ExecutableFinder())->find('composer');
        if ($composer !== null && (!$exists || $update)) {
            $io->section('Composer autoloader');
            $this->runOrPrint(
                $io,
                $dryRun,
                [$composer, 'install', '--no-dev', '--no-interaction', '--no-progress', '--quiet'],
                $target,
                allowFailure: true,
            );
        }

        if ($input->getOption('activate')) {
            $io->section('Activating');
            $this->activate($io, $dryRun);
        }

        $this->printNextSteps($io);

        return Command::SUCCESS;
    }

    /**
     * The theme lives at `<wp-root>/wp-content/themes/<theme>`, so the
     * plugins directory is two levels up + `/plugins`. Falls back to
     * {@see WpLoader::locate()} (which walks up looking for wp-load.php)
     * when the theme isn't in the standard location.
     */
    public static function resolvePluginsDir(string $themeDir): ?string
    {
        $wpLoad = WpLoader::locate($themeDir);
        if ($wpLoad !== null) {
            $candidate = dirname($wpLoad) . '/wp-content/plugins';
            if (is_dir($candidate)) {
                return $candidate;
            }
        }

        $candidate = dirname($themeDir, 2) . '/plugins';

        return is_dir($candidate) ? $candidate : null;
    }

    /**
     * @param list<string> $command
     */
    private function runOrPrint(SymfonyStyle $io, bool $dryRun, array $command, string $cwd, bool $allowFailure = false): bool
    {
        $printable = implode(' ', $command);
        if ($dryRun) {
            $io->writeln("  <comment>would run:</comment> {$printable}");

            return true;
        }

        $io->writeln("  <fg=gray>{$printable}</>");
        $process = new Process($command, $cwd, null, null, null);
        $process->run(static function (string $type, string $buffer) use ($io): void {
            $io->write($buffer);
        });

        if (!$process->isSuccessful() && !$allowFailure) {
            $io->error('Command failed.');

            return false;
        }
        if (!$process->isSuccessful()) {
            $io->warning('Non-fatal: continuing without it.');
        }

        return true;
    }

    private function activate(SymfonyStyle $io, bool $dryRun): void
    {
        $wp = (new ExecutableFinder())->find('wp');
        if ($wp === null) {
            $io->warning('`wp` not on PATH — activate manually: wp plugin activate ' . self::PLUGIN);

            return;
        }

        $wpLoad = WpLoader::locate($this->themeDir);
        $wpRoot = $wpLoad !== null ? dirname($wpLoad) : null;
        $socket = WpLoader::resolveLocalSocket($this->themeDir);

        $command = $socket !== null
            ? [PHP_BINARY, '-d', "mysqli.default_socket={$socket}", '-d', "pdo_mysql.default_socket={$socket}", $wp]
            : [$wp];
        if ($wpRoot !== null) {
            $command[] = "--path={$wpRoot}";
        }
        $command[] = 'plugin';
        $command[] = 'activate';
        $command[] = self::PLUGIN;

        $this->runOrPrint($io, $dryRun, $command, $this->themeDir, allowFailure: true);
    }

    private function printNextSteps(SymfonyStyle $io): void
    {
        $io->section('Next steps');
        $io->writeln([
            'The plugin is <info>inert</info> until you configure it. In <comment>wp-config.php</comment>:',
            '',
            "    <fg=gray>define('TAW_HUB_PUBLIC_KEY', '…base64 Ed25519 public key from your Hub…');</>",
            "    <fg=gray>define('TAW_HUB_KEY_ID', 'hub-local'); // optional</>",
            '',
            'Then hand the Hub this site\'s identity so it can verify signed responses:',
            '',
            '    <fg=gray>php bin/taw wp option get taw_hub_companion_public_key</>',
            '    <fg=gray>php bin/taw wp option get taw_hub_companion_key_id</>',
            '',
            'The <info>hub-connect</info> skill walks an agent through all of this interactively.',
        ]);
    }
}
