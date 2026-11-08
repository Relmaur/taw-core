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
use TAW\Core\Lens\Bridge;

/**
 * `taw lens`: starts the TAW Lens bridge for this theme (umbrella ADR-0006 § 8), in the foreground.
 *
 * The bridge (resources/lens-bridge/bridge.mjs, zero dependencies) runs the developer's own `claude` CLI in
 * the theme folder and talks to the Lens panel on the local site over 127.0.0.1. This command checks what it
 * needs (Node, a recent enough Claude Code), works out the site's address (Local's site list, or --origin),
 * and hands over to Node. Ctrl-C stops it.
 *
 *   taw lens                         # start (a new conversation)
 *   taw lens --resume                # continue the last conversation
 *   taw lens --check                 # what it would use, without starting
 *   taw lens --prompt                # the instructions the agent gets (the human guide too)
 *   taw lens --origin=https://acme.test --model=sonnet --permission-mode=default
 *
 * Doesn't boot WordPress, and doesn't need it: nothing here touches the database.
 */
class LensCommand extends Command
{
    /** The oldest Claude Code the bridge's protocol was checked against (tests/js/lens-bridge-contract.test.mjs). */
    public const MIN_CLAUDE = '2.1.319';

    public const MIN_NODE = '20.0.0';

    public const DEFAULT_PORT = 47615;

    public const PERMISSION_MODES = ['default', 'acceptEdits', 'auto', 'plan'];

    private string $themeDir;

    public function __construct(string $themeDir)
    {
        parent::__construct();
        $this->themeDir = $themeDir;
    }

    protected function configure(): void
    {
        $this
            ->setName('lens')
            ->setDescription('Start TAW Lens for this theme: point at the local site and tell Claude what to change')
            ->setHelp(<<<'HELP'
                Starts the TAW Lens bridge in the foreground. Then open the local site in your browser, logged in,
                with the Vite dev server running and TAW Settings → Developer Tools → "Enable Lens" checked:
                the Lens panel connects on its own. Ctrl-C stops it.

                Lens runs your own `claude` CLI in this theme folder, with your Claude settings (permission mode
                included) and without your MCP servers. Use --permission-mode=default for this session to have
                every change ask in the panel first.
                HELP)
            ->addOption('origin', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, "The site's address (e.g. https://acme.local). Found in Local's site list when not given")
            ->addOption('port', null, InputOption::VALUE_REQUIRED, 'Port to listen on (127.0.0.1 only); any free one if taken', (string) self::DEFAULT_PORT)
            ->addOption('model', null, InputOption::VALUE_REQUIRED, "Claude model for this session (default: your CLI's)")
            ->addOption('resume', null, InputOption::VALUE_OPTIONAL, 'Continue a conversation: the last one, or a session id', false)
            ->addOption('permission-mode', null, InputOption::VALUE_REQUIRED, 'Permission mode for this session: ' . implode(', ', self::PERMISSION_MODES) . " (default: your Claude settings')")
            ->addOption('check', null, InputOption::VALUE_NONE, 'Check the setup and show what it would use, without starting')
            ->addOption('prompt', null, InputOption::VALUE_NONE, 'Print the instructions the agent gets, and stop');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $bridge = self::bridgeDir() . '/bridge.mjs';

        if ($input->getOption('prompt')) {
            $output->write((string) file_get_contents(self::bridgeDir() . '/agent-prompt.md'));

            return Command::SUCCESS;
        }

        $theme = realpath($this->themeDir) ?: $this->themeDir;
        $problems = [];

        $node = self::find('TAW_LENS_NODE', 'node');
        $nodeVersion = $node !== null ? self::versionOf($node) : null;
        if ($node === null) {
            $problems[] = 'Node.js isn\'t installed (or not on your PATH). Install Node ' . self::MIN_NODE . ' or newer: https://nodejs.org';
        } elseif ($nodeVersion === null || version_compare($nodeVersion, self::MIN_NODE, '<')) {
            $problems[] = sprintf('Node %s is too old: Lens needs %s or newer.', $nodeVersion ?? '(unknown)', self::MIN_NODE);
        }

        $claude = self::find('TAW_LENS_CLAUDE', 'claude', self::claudeDirs());
        $claudeVersion = $claude !== null ? self::versionOf($claude) : null;
        if ($claude === null) {
            $problems[] = 'Claude Code isn\'t installed (or not on your PATH): https://docs.claude.com/en/docs/claude-code';
        } elseif ($claudeVersion === null || version_compare($claudeVersion, self::MIN_CLAUDE, '<')) {
            $problems[] = sprintf('Claude Code %s is too old: Lens needs %s or newer. Run `claude update`.', $claudeVersion ?? '(unknown)', self::MIN_CLAUDE);
        }

        $origins = [];
        foreach ((array) $input->getOption('origin') as $given) {
            $origin = self::origin((string) $given);
            if ($origin === null) {
                $problems[] = sprintf('--origin %s isn\'t a site address: use e.g. https://acme.local', (string) $given);
                continue;
            }
            $origins[] = $origin;
        }
        if ($origins === [] && !$input->getOption('origin')) {
            $origins = self::localOrigins(self::localSites(), $theme);
            if ($origins === []) {
                $problems[] = 'Couldn\'t find this theme\'s site in Local. Give its address: taw lens --origin=https://your-site.local';
            }
        }

        $mode = $input->getOption('permission-mode');
        if ($mode !== null && !in_array($mode, self::PERMISSION_MODES, true)) {
            $problems[] = sprintf('--permission-mode %s: use one of %s.', (string) $mode, implode(', ', self::PERMISSION_MODES));
        }

        $port = (int) $input->getOption('port');
        if ($port < 1 || $port > 65535) {
            $problems[] = '--port must be a number from 1 to 65535.';
        }

        $resume = $input->getOption('resume');
        $session = null;
        if ($resume !== false) {
            $session = is_string($resume) && $resume !== '' ? $resume : Bridge::lastSession($theme);
            if ($session === null) {
                $problems[] = 'There is no earlier Lens conversation for this theme to resume. Run `taw lens` to start one.';
            } elseif (preg_match('/^[A-Za-z0-9-]{8,64}$/', $session) !== 1) {
                $problems[] = '--resume takes a session id like the one `taw lens` prints when it stops.';
            }
        }

        $running = Bridge::current($theme);

        if ($input->getOption('check')) {
            $io->definitionList(
                ['Theme' => $theme],
                ['Site' => $origins !== [] ? implode(', ', $origins) : '(not found)'],
                ['Node' => $node !== null ? sprintf('%s (%s)', $node, $nodeVersion ?? '?') : '(not found)'],
                ['Claude Code' => $claude !== null ? sprintf('%s (%s)', $claude, $claudeVersion ?? '?') : '(not found)'],
                ['Bridge' => $running !== null ? sprintf('running (process %d, port %d)', $running['pid'], $running['port']) : 'not running'],
            );
        }
        if ($problems !== []) {
            $io->error($problems);

            return Command::FAILURE;
        }
        if ($running !== null) {
            $io->warning(sprintf('TAW Lens is already running for this theme (process %d, port %d). Use that one, or stop it (Ctrl-C in its terminal) first.', $running['pid'], $running['port']));

            return $input->getOption('check') ? Command::SUCCESS : Command::FAILURE;
        }
        if ($input->getOption('check')) {
            $io->success('Ready. Run `taw lens` to start.');

            return Command::SUCCESS;
        }

        /** @var string $node */
        /** @var string $claude */
        $args = self::bridgeArgs($bridge, $theme, $origins, $port, [
            'claude'          => $claude,
            'model'           => (string) $input->getOption('model'),
            'resume'          => (string) $session,
            'permission-mode' => (string) $mode,
        ]);

        // Hand the terminal to Node: Ctrl-C then reaches the bridge directly, which cleans up after itself.
        if (function_exists('pcntl_exec') && @chdir($theme)) {
            pcntl_exec($node, $args);
        }
        $process = proc_open([$node, ...$args], [STDIN, STDOUT, STDERR], $pipes, $theme);

        return is_resource($process) ? proc_close($process) : Command::FAILURE;
    }

    /** Where the bridge lives in this taw/core. */
    public static function bridgeDir(): string
    {
        return dirname(__DIR__, 2) . '/resources/lens-bridge';
    }

    /**
     * The bridge's command line.
     *
     * @param list<string> $origins
     * @param array<string, string> $options Empty values are left out.
     * @return list<string>
     */
    public static function bridgeArgs(string $bridge, string $theme, array $origins, int $port, array $options): array
    {
        $args = [$bridge, '--theme', $theme];
        foreach ($origins as $origin) {
            $args[] = '--origin';
            $args[] = $origin;
        }
        $args[] = '--port';
        $args[] = (string) $port;
        foreach ($options as $name => $value) {
            if ($value !== '') {
                $args[] = '--' . $name;
                $args[] = $value;
            }
        }

        return $args;
    }

    /** A site address as a browser sends it in Origin: scheme, host and port only. Null when it isn't one. */
    public static function origin(string $url): ?string
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['query'])) {
            return null;
        }
        if (isset($parts['path']) && $parts['path'] !== '/') {
            return null;
        }

        return $scheme . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The site's addresses from Local's site list: the site whose folder holds the theme, or whose themes folder
     * links to it (a theme kept elsewhere and symlinked in). Both schemes: Local serves either.
     *
     * @param array<mixed> $sites Local's sites.json, decoded.
     * @return list<string>
     */
    public static function localOrigins(array $sites, string $theme): array
    {
        $theme = rtrim(realpath($theme) ?: $theme, '/');
        foreach ($sites as $site) {
            if (!is_array($site) || !is_string($site['domain'] ?? null) || !is_string($site['path'] ?? null)) {
                continue;
            }
            $domain = strtolower($site['domain']);
            if (preg_match('/^[a-z0-9.-]+(:\d+)?$/', $domain) !== 1) {
                continue;
            }
            $path = self::expandHome($site['path']);
            $root = rtrim(realpath($path) ?: $path, '/');
            $linked = false;
            foreach (glob($root . '/app/public/wp-content/themes/*', GLOB_ONLYDIR) ?: [] as $dir) {
                if ((realpath($dir) ?: $dir) === $theme) {
                    $linked = true;
                    break;
                }
            }
            if ($linked || str_starts_with($theme, $root . '/')) {
                return ['https://' . $domain, 'http://' . $domain];
            }
        }

        return [];
    }

    /** The first x.y.z in a program's `--version`. */
    public static function versionOf(string $program): ?string
    {
        $process = new Process([$program, '--version']);
        $process->setTimeout(15);
        try {
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        return self::parseVersion($process->getOutput());
    }

    public static function parseVersion(string $output): ?string
    {
        return preg_match('/(\d+\.\d+\.\d+)/', $output, $m) === 1 ? $m[1] : null;
    }

    /** @return array<mixed> */
    private static function localSites(): array
    {
        $home = getenv('HOME');
        if (!is_string($home) || $home === '') {
            return [];
        }
        foreach ([$home . '/Library/Application Support/Local', $home . '/.config/Local', $home . '/AppData/Roaming/Local'] as $dir) {
            $sites = is_file($dir . '/sites.json') ? json_decode((string) file_get_contents($dir . '/sites.json'), true) : null;
            if (is_array($sites)) {
                return $sites;
            }
        }

        return [];
    }

    /**
     * An executable: the environment variable's path when set, else from PATH and the extra folders.
     *
     * @param list<string> $extraDirs
     */
    private static function find(string $env, string $name, array $extraDirs = []): ?string
    {
        $given = getenv($env);
        if (is_string($given) && $given !== '') {
            return is_executable($given) ? $given : null;
        }

        return (new ExecutableFinder())->find($name, null, [...$extraDirs, '/opt/homebrew/bin', '/usr/local/bin']);
    }

    /** @return list<string> Where Claude Code's installers put `claude` besides PATH. */
    private static function claudeDirs(): array
    {
        $home = getenv('HOME');

        return is_string($home) && $home !== '' ? [$home . '/.claude/local', $home . '/.local/bin'] : [];
    }

    private static function expandHome(string $path): string
    {
        $home = getenv('HOME');

        return is_string($home) && $home !== '' && str_starts_with($path, '~/') ? $home . substr($path, 1) : $path;
    }
}
