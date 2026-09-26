<?php

declare(strict_types=1);

namespace TAW\CLI;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Thin passthrough to WordPress's own official CLI (the `wp` binary), with
 * the Local by Flywheel socket workaround (WpLoader::resolveLocalSocket())
 * and `--path` applied automatically:
 *
 *   php bin/taw wp post list --post_type=page
 *
 * instead of hand-typing, every single time:
 *
 *   php -d mysqli.default_socket=... -d pdo_mysql.default_socket=... \
 *     "$(which wp)" --path=/abs/path/to/wp-root post list --post_type=page
 *
 * Deliberately NOT a normally-parsed Symfony command beyond its own name —
 * every argument after `wp` is forwarded to the real `wp` binary completely
 * unparsed. WP-CLI has its own flag syntax (`--fields=ID,post_title`,
 * `--post_type=page`) that Symfony's own option parser would otherwise
 * misinterpret as options belonging to THIS command and reject.
 */
class WpCliCommand extends Command
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
            ->setName('wp')
            ->setDescription("Run WordPress's own WP-CLI, with the Local by Flywheel socket + --path resolved automatically")
            ->setHelp(<<<'HELP'
                A thin passthrough to the real `wp` binary — every argument after `wp` is
                forwarded exactly as given, unparsed. Resolves two things WP-CLI otherwise
                needs hand-configured every time under Local by Flywheel: the per-site
                MySQL socket (see WpLoader::resolveLocalSocket()) and --path (the
                WordPress root, derived by walking up from the theme directory — same
                logic WpLoader::locate() already uses for wp-load.php).

                A no-op wrapper everywhere the socket workaround doesn't apply (real
                hosting, CI, DDEV, Herd, other local environments) — it still resolves
                --path and runs `wp` normally, just without the extra -d flags.

                Examples:
                  <info>php bin/taw wp post list --post_type=page --fields=ID,post_title</info>
                  <info>php bin/taw wp option get siteurl</info>
                  <info>php bin/taw wp eval 'echo home_url();'</info>
                  <info>php bin/taw wp shell</info>
                HELP)
            ->ignoreValidationErrors();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $wpBinary = (new ExecutableFinder())->find('wp');
        if ($wpBinary === null) {
            $output->writeln('<error>Could not find the `wp` binary on PATH. Install WP-CLI: https://wp-cli.org/#installing</error>');
            return Command::FAILURE;
        }

        $wpLoad = WpLoader::locate($this->themeDir);
        if ($wpLoad === null) {
            $output->writeln('<error>Could not locate wp-load.php by walking up from the theme directory.</error>');
            return Command::FAILURE;
        }
        $wpRoot = dirname($wpLoad);

        $socket = WpLoader::resolveLocalSocket($this->themeDir);
        $expected = WpLoader::expectedLocalSocket($this->themeDir);
        if ($socket === null && $expected !== null) {
            // A Local site, but stopped: without this, MySQL falls back to
            // /tmp/mysql.sock and fails with a message that points nowhere.
            $errorOutput = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errorOutput->writeln("<comment>This Local site's database isn't running (no socket at {$expected}). Start the site in Local, then run the command again.</comment>");
        }

        [$command, $env] = self::processSpec($wpBinary, $wpRoot, $socket, $this->passthroughArgs());

        $process = new Process($command, null, $env);
        $process->setTimeout(null);

        // A real interactive terminal's stdin is always a TTY; piped or
        // redirected stdin (`wp eval-file -`, `echo ... | wp eval-file -`)
        // never is — that's the standard Unix signal for "forward this."
        // Using it this way (rather than always forwarding stdin) avoids
        // the child process blocking on a live interactive session that
        // has nothing to send, which plain `setInput(STDIN)` unconditionally
        // would risk.
        if (Process::isTtySupported() && stream_isatty(STDIN)) {
            $process->setTty(true);
        } elseif (!stream_isatty(STDIN)) {
            $process->setInput(STDIN);
        }

        $process->run(static function (string $type, string $buffer) use ($output): void {
            $output->write($buffer);
        });

        return $process->getExitCode() ?? Command::FAILURE;
    }

    /**
     * The `wp` process: its command line and extra environment.
     *
     * With a Local socket, PHP's MySQL drivers get it through `-d` flags,
     * and the `mysql`/`mysqldump` clients that `wp db query|export|import|cli`
     * start get it through MYSQL_UNIX_PORT. Those clients never read PHP's
     * ini settings, so without the variable they fall back to
     * /tmp/mysql.sock (ERROR 2002).
     *
     * @param list<string> $args
     * @return array{0: list<string>, 1: array<string, string>}
     */
    public static function processSpec(string $wpBinary, string $wpRoot, ?string $socket, array $args): array
    {
        $command = $socket !== null
            ? [PHP_BINARY, '-d', "mysqli.default_socket={$socket}", '-d', "pdo_mysql.default_socket={$socket}", $wpBinary]
            : [$wpBinary];

        $command[] = "--path={$wpRoot}";
        array_push($command, ...$args);

        return [$command, $socket !== null ? ['MYSQL_UNIX_PORT' => $socket] : []];
    }

    /**
     * Everything after the 'wp' token in the real argv, forwarded verbatim —
     * deliberately bypassing Symfony's own argument parsing (see class
     * docblock). `array_search` finds the leftmost 'wp' (the command name
     * itself, always argv[1] for `php bin/taw wp ...`); a later argument
     * that happens to also equal the literal string 'wp' is outside this
     * command's realistic usage.
     *
     * @return list<string>
     */
    private function passthroughArgs(): array
    {
        $argv = $_SERVER['argv'] ?? [];
        $index = array_search('wp', $argv, true);

        return $index !== false ? array_values(array_slice($argv, $index + 1)) : [];
    }
}
