<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\CLI;

use TAW\CLI\ManifestMerge;
use TAW\Tests\TestCase;

/**
 * The structured Tier 2 manifests get rule-based suggestions instead of a
 * diff to judge (update-manifest.json § manifestMerge). The fixtures are a
 * real client site's composer.json/package.json (ls-mexico, with the key and
 * author scrubbed) and the scaffold's: its raw diff proposes removing the
 * site's own dependencies, taw/hub-companion and the taw-fleet key, and the
 * plan has to say there is nothing to apply.
 */
final class ManifestMergeTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../../fixtures/manifest-merge/';

    public function test_a_real_site_has_nothing_to_apply(): void
    {
        $plan = ManifestMerge::plan($this->fixture('site-composer.json'), $this->fixture('scaffold-composer.json'), $this->rules('composer.json'));

        $this->assertSame([], $plan['add']);
        $this->assertSame([], $plan['bump']);
        $this->assertSame([], $plan['review']);
        $this->assertSame(
            ['require.reactiph/taw-bridge', 'repositories[https://github.com/Relmaur/reactiph]', 'repositories[https://github.com/Relmaur/reactiph-wordpress-bridge]',
                'repositories[https://github.com/Relmaur/reactiph-taw-bridge]', 'minimum-stability', 'prefer-stable'],
            array_column($plan['optional'], 'label'),
        );
        $this->assertSame(['reactiph'], array_values(array_unique(array_column($plan['optional'], 'feature'))));
        // emailit + taw/hub-companion + the companion's repository: the site's own.
        $this->assertSame(3, $plan['site_only']);

        $plan = ManifestMerge::plan($this->fixture('site-package.json'), $this->fixture('scaffold-package.json'), $this->rules('package.json'));
        $this->assertSame([], $plan['add']);
        $this->assertSame([], $plan['bump']);
        $this->assertSame(['devDependencies.dompurify', 'devDependencies.marked'], array_column($plan['optional'], 'label'));
        $this->assertSame(1, $plan['site_only']); // photoswipe
    }

    public function test_additions_bumps_and_reviews(): void
    {
        $site = [
            'name' => 'acme/theme',
            'require' => ['php' => '>=8.2', 'taw/core' => '^1.0', 'acme/thing' => '^3.0'],
            'require-dev' => ['phpstan/phpstan' => '^1.10', 'phpunit/phpunit' => '^12', 'brain/monkey' => '*'],
            'autoload' => ['psr-4' => ['TAW\\Blocks\\' => 'src/Blocks/']],
            'scripts' => ['phpstan' => 'phpstan analyse --level=8'],
            'extra' => ['taw-companion' => ['keys' => ['taw-fleet' => 'k']]],
        ];
        $scaffold = [
            'name' => 'taw/theme',
            'require' => ['php' => '>=8.2', 'taw/core' => '^2.0'],
            'require-dev' => ['phpstan/phpstan' => '^2.2', 'phpunit/phpunit' => '^11', 'brain/monkey' => '^2.6'],
            'autoload' => ['psr-4' => ['TAW\\Blocks\\' => 'Blocks/']],
            'autoload-dev' => ['psr-4' => ['TAW\\Theme\\Tests\\' => 'tests/']],
            'scripts' => ['phpstan' => 'phpstan analyse --memory-limit=-1', 'test' => 'phpunit'],
            'extra' => ['branch-alias' => []],
        ];
        $plan = ManifestMerge::plan($site, $scaffold, $this->rules('composer.json'));

        $this->assertSame(['autoload-dev', 'scripts.test'], array_column($plan['add'], 'label'));
        $this->assertSame(['require.taw/core', 'require-dev.phpstan/phpstan'], array_column($plan['bump'], 'label'));
        $this->assertSame(['^1.0', '^2.0'], [$plan['bump'][0]['from'], $plan['bump'][0]['to']]);
        // phpunit ^12 is newer than the scaffold's ^11: not a downgrade, not a review.
        $this->assertSame(['require-dev.brain/monkey', 'autoload.psr-4.TAW\\Blocks\\', 'scripts.phpstan'], array_column($plan['review'], 'label'));
        $this->assertSame("constraints can't be compared", $plan['review'][0]['reason']);
        $this->assertSame(1, $plan['site_only']); // acme/thing; name and extra are never read
    }

    public function test_apply_writes_only_additions_and_bumps_in_place(): void
    {
        $site = [
            'name' => 'acme/theme',
            'require' => ['php' => '>=8.2', 'taw/core' => '^1.0', 'acme/thing' => '^3.0'],
            'repositories' => [['type' => 'vcs', 'url' => 'https://github.com/acme/thing']],
            'scripts' => ['phpstan' => 'custom'],
            'extra' => ['taw-companion' => ['keys' => ['taw-fleet' => 'k']]],
        ];
        $scaffold = [
            'require' => ['php' => '>=8.2', 'taw/core' => '^1.80'],
            'repositories' => [['type' => 'vcs', 'url' => 'https://github.com/Relmaur/taw-core']],
            'scripts' => ['phpstan' => 'phpstan analyse', 'test' => 'phpunit'],
            'autoload-dev' => ['psr-4' => ['TAW\\Theme\\Tests\\' => 'tests/']],
        ];
        $merged = ManifestMerge::apply($site, ManifestMerge::plan($site, $scaffold, $this->rules('composer.json')));

        $this->assertSame(['name', 'require', 'repositories', 'scripts', 'extra', 'autoload-dev'], array_keys($merged));
        $this->assertSame(['php' => '>=8.2', 'taw/core' => '^1.80', 'acme/thing' => '^3.0'], $merged['require']);
        $this->assertSame(['https://github.com/acme/thing', 'https://github.com/Relmaur/taw-core'], array_column($merged['repositories'], 'url'));
        $this->assertSame(['phpstan' => 'custom', 'test' => 'phpunit'], $merged['scripts'], 'a changed script is for review, never overwritten');
        $this->assertSame($site['extra'], $merged['extra']);
    }

    public function test_encode_keeps_the_files_indentation_and_final_newline(): void
    {
        $original = "{\n  \"private\": true,\n  \"devDependencies\": {\n    \"vite\": \"^7.3.1\"\n  }\n}\n";
        $data = ['private' => true, 'devDependencies' => ['vite' => '^7.3.1', 'sass' => '^1.97.2']];

        $this->assertSame(
            "{\n  \"private\": true,\n  \"devDependencies\": {\n    \"vite\": \"^7.3.1\",\n    \"sass\": \"^1.97.2\"\n  }\n}\n",
            ManifestMerge::encode($data, $original),
        );
        $this->assertSame(
            "{\n    \"autoload\": {\n        \"psr-4\": {\n            \"TAW\\\\Blocks\\\\\": \"Blocks/\"\n        }\n    }\n}",
            ManifestMerge::encode(['autoload' => ['psr-4' => ['TAW\\Blocks\\' => 'Blocks/']]], "{\n    \"a\": 1\n}"),
        );
    }

    public function test_the_real_site_files_survive_an_apply_unchanged(): void
    {
        foreach (['composer.json', 'package.json'] as $file) {
            $raw = (string) file_get_contents(self::FIXTURES . 'site-' . $file);
            $site = $this->fixture('site-' . $file);
            $plan = ManifestMerge::plan($site, $this->fixture('scaffold-' . $file), $this->rules($file));

            $this->assertSame($raw, ManifestMerge::encode(ManifestMerge::apply($site, $plan), $raw), $file);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $data = json_decode((string) file_get_contents(self::FIXTURES . $name), true);
        $this->assertIsArray($data);

        return $data;
    }

    /**
     * The shipped rules, so a change to them is tested too.
     *
     * @return array{sections?: list<string>, dependencySections?: list<string>, optional?: array<string, list<string>>}
     */
    private function rules(string $file): array
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/update-manifest.json'), true);
        $this->assertIsArray($manifest);

        return $manifest['manifestMerge'][$file];
    }
}
