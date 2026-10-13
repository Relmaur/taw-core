<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CI;

use PHPUnit\Framework\TestCase;

/**
 * resources/ci/deploy.sh, the shared theme deploy: what ships, what is refused,
 * and the git method against a local bare repository. Real bash, rsync and git in a
 * temp folder; the SSH method's transfer itself needs a server, so only its checks run.
 */
final class DeployScriptTest extends TestCase
{
    private string $dir;
    private string $theme;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = (string) realpath(sys_get_temp_dir()) . '/taw-deploy-' . bin2hex(random_bytes(4));
        $this->theme = $this->dir . '/acme';
        foreach ([
            'style.css' => "/* Theme Name: Acme */\n",
            'functions.php' => "<?php\n",
            'Blocks/Hero/Hero.php' => "<?php\n",
            'vendor/autoload.php' => "<?php\n",
            'vendor/pkg/.gitignore' => "*\n",
            'public/build/app.js' => "x\n",
            'README.md' => "dev notes\n",
            'node_modules/x/index.js' => "x\n",
            'tests/HeroTest.php' => "<?php\n",
            '.sync/snapshot.sql' => "-- MySQL dump\n",
            '.env' => "SECRET=1\n",
            '.taw/update-report.md' => "report\n",
            'resources/scss/app.scss' => "a{}\n",
            '.deployignore' => "# sources Vite compiles\nresources/scss\n",
        ] as $path => $body) {
            @mkdir(dirname($this->theme . '/' . $path), 0777, true);
            file_put_contents($this->theme . '/' . $path, $body);
        }
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
        parent::tearDown();
    }

    /** @param array<string, string> $env @return array{int, string} */
    private function deploy(array $env): array
    {
        $vars = '';
        foreach ($env as $k => $v) {
            $vars .= $k . '=' . escapeshellarg($v) . ' ';
        }
        $script = escapeshellarg(dirname(__DIR__, 3) . '/resources/ci/deploy.sh');
        exec('cd ' . escapeshellarg($this->theme) . ' && env ' . $vars . 'bash ' . $script . ' 2>&1', $out, $code);

        return [$code, implode("\n", $out)];
    }

    public function test_a_dry_run_builds_and_checks_the_payload(): void
    {
        [$code, $out] = $this->deploy(['DEPLOY_METHOD' => 'rsync', 'DEPLOY_DRY_RUN' => '1']);
        $this->assertSame(0, $code, $out);
        // style.css, functions.php, Hero.php, vendor/autoload.php, public/build/app.js: not README.md,
        // node_modules, tests, .sync, .env, .taw, vendor's .gitignore or what .deployignore names.
        $this->assertStringContainsString('Payload: 5 files from acme', $out);
        $this->assertStringContainsString('Dry run: nothing shipped.', $out);
    }

    public function test_it_refuses_a_dump_or_credentials_whatever_the_excludes_say(): void
    {
        file_put_contents($this->theme . '/wp-config.php', "<?php define('DB_PASSWORD', 'x');\n");
        @mkdir($this->theme . '/inc');
        file_put_contents($this->theme . '/inc/key.php', "-----BEGIN OPENSSH PRIVATE KEY-----\n");
        [$code, $out] = $this->deploy(['DEPLOY_METHOD' => 'rsync', 'DEPLOY_DRY_RUN' => '1']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('wp-config.php: a dump, snapshot or credentials file is never deployed', $out);
        $this->assertStringContainsString('inc/key.php: looks like a database dump or a private key', $out);
        $this->assertStringContainsString('nothing was deployed', $out);
    }

    public function test_rsync_needs_a_theme_folder_on_the_server(): void
    {
        [$code, $out] = $this->deploy(['DEPLOY_METHOD' => 'rsync', 'DEPLOY_HOST' => 'example.com', 'DEPLOY_USER' => 'u', 'DEPLOY_PATH' => '/home/u/public_html']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('DEPLOY_PATH must be the theme', $out);

        [$code, $out] = $this->deploy(['DEPLOY_METHOD' => 'ftp']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('DEPLOY_METHOD must be rsync or git', $out);
    }

    public function test_git_pushes_the_built_theme_under_its_path(): void
    {
        $remote = $this->dir . '/host.git';
        exec('git init -q --bare ' . escapeshellarg($remote));
        [$code, $out] = $this->deploy(['DEPLOY_METHOD' => 'git', 'DEPLOY_GIT_REMOTE' => $remote, 'GITHUB_SHA' => 'abc123']);
        $this->assertSame(0, $code, $out);
        $files = trim((string) shell_exec('git -C ' . escapeshellarg($remote) . ' ls-tree -r --name-only master'));
        $this->assertSame(implode("\n", [
            'wp-content/themes/acme/Blocks/Hero/Hero.php',
            'wp-content/themes/acme/functions.php',
            'wp-content/themes/acme/public/build/app.js',
            'wp-content/themes/acme/style.css',
            'wp-content/themes/acme/vendor/autoload.php',
        ]), $files);
        $this->assertSame('Deploy abc123', trim((string) shell_exec('git -C ' . escapeshellarg($remote) . ' log -1 --format=%s master')));

        [$code, $out] = $this->deploy(['DEPLOY_METHOD' => 'git', 'DEPLOY_GIT_REMOTE' => $remote, 'DEPLOY_GIT_PATH' => '../escape']);
        $this->assertNotSame(0, $code);
        $this->assertStringContainsString('DEPLOY_GIT_PATH must be a relative path', $out);
    }

    public function test_the_stub_passes_only_what_the_shared_workflow_takes(): void
    {
        $root = dirname(__DIR__, 3);
        $workflow = (string) file_get_contents($root . '/.github/workflows/theme-deploy.yml');
        $stub = (string) file_get_contents($root . '/resources/deploy/deploy.yml');
        preg_match('/^    with:\n((?:      [a-z-]+:.*\n)+)/m', $stub, $with);
        $this->assertNotEmpty($with, 'the stub has a with: block');
        preg_match_all('/^      ([a-z-]+):/m', $with[1], $keys);
        foreach ($keys[1] as $key) {
            $this->assertMatchesRegularExpression('/^      ' . preg_quote($key, '/') . ':$/m', $workflow, "theme-deploy.yml takes $key");
        }
        $this->assertStringContainsString('ssh-key: ${{ secrets.DEPLOY_SSH_KEY }}', $stub);
        $this->assertStringContainsString("vars.DEPLOY_METHOD != ''", $stub, 'it does nothing until set up');
    }
}
