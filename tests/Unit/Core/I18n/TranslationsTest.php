<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\I18n;

use Brain\Monkey\Functions;
use TAW\Core\I18n\Translations;
use TAW\Tests\TestCase;

/**
 * taw-core's own text domain (data layer Phase 2, Step 5): the bundled
 * translations load from the package, and the theme's `taw-theme`
 * translation of a string wins when it has one.
 */
final class TranslationsTest extends TestCase
{
    /** @var array<string, object> */
    private array $domains = [];

    protected function setUp(): void
    {
        parent::setUp();
        Translations::reset();
        $this->domains = [];
        Functions\when('get_translations_for_domain')->alias(fn (string $domain) => $this->domains[$domain] ?? self::catalog([]));
    }

    protected function tearDown(): void
    {
        Translations::reset();
        unset($GLOBALS['wp_textdomain_registry']);
        parent::tearDown();
    }

    /**
     * A translations object like WordPress's, over "ctx\4text" → forms.
     *
     * @param array<string, list<string>> $messages
     */
    private static function catalog(array $messages): object
    {
        return new class ($messages) {
            /** @param array<string, list<string>> $messages */
            public function __construct(private array $messages)
            {
            }

            public function translate(string $text, ?string $context = null): string
            {
                return $this->messages[($context !== null ? $context . "\4" : '') . $text][0] ?? $text;
            }

            public function translate_plural(string $single, string $plural, int $count, ?string $context = null): string
            {
                $forms = $this->messages[($context !== null ? $context . "\4" : '') . $single] ?? null;

                return $forms === null ? ($count === 1 ? $single : $plural) : $forms[$count === 1 ? 0 : 1];
            }

            public function __get(string $name): mixed
            {
                if ($name !== 'entries') {
                    return null;
                }
                $entries = [];
                foreach ($this->messages as $key => $forms) {
                    $entry = new \Translation_Entry();
                    $parts = explode("\4", $key);
                    if (isset($parts[1])) {
                        [$entry->context, $entry->singular] = $parts;
                    } else {
                        $entry->singular = $key;
                    }
                    $entry->translations = $forms;
                    $entry->is_plural = count($forms) > 1;
                    $entries[] = $entry;
                }

                return $entries;
            }
        };
    }

    public function test_register_points_the_registry_at_the_package_and_hooks_the_fallback_once(): void
    {
        $GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();

        Translations::register();
        Translations::register();

        $this->assertStringEndsWith('/languages', $GLOBALS['wp_textdomain_registry']->customPaths['taw-core']);
        $this->assertSame(10, has_filter('gettext_taw-core', [Translations::class, 'gettext']));
        $this->assertSame(10, has_filter('gettext_with_context_taw-core', [Translations::class, 'gettextWithContext']));
        $this->assertSame(10, has_filter('ngettext_taw-core', [Translations::class, 'ngettext']));
        $this->assertSame(10, has_filter('ngettext_with_context_taw-core', [Translations::class, 'ngettextWithContext']));
    }

    public function test_the_themes_translation_wins_then_the_bundled_one(): void
    {
        $this->domains['taw-theme'] = self::catalog(['Add Row' => ['Agregar Renglón'], "verb\4Clear" => ['Limpiar']]);

        $this->assertSame('Agregar Renglón', Translations::gettext('Añadir fila', 'Add Row'), 'the site keeps its own wording');
        $this->assertSame('Quitar', Translations::gettext('Quitar', 'Remove'), 'bundled when the theme has none');
        $this->assertSame('Remove', Translations::gettext('Remove', 'Remove'), 'English when neither has it');
        $this->assertSame('Limpiar', Translations::gettextWithContext('Borrar', 'Clear', 'verb'));
        $this->assertSame('Borrar', Translations::gettextWithContext('Borrar', 'Clear', 'noun'));
    }

    public function test_plurals_fall_back_the_same_way(): void
    {
        $this->domains['taw-theme'] = self::catalog(['%d file' => ['%d archivo', '%d archivos']]);

        $this->assertSame('%d archivos', Translations::ngettext('%d ficheros', '%d file', '%d files', 3));
        $this->assertSame('%d archivo', Translations::ngettext('%d fichero', '%d file', '%d files', 1));
        $this->assertSame('%d bloques', Translations::ngettext('%d bloques', '%d block', '%d blocks', 2));
        $this->assertSame('%d bloques', Translations::ngettextWithContext('%d bloques', '%d block', '%d blocks', 2, 'ctx'));
    }

    public function test_script_locale_data_is_null_without_translations(): void
    {
        $this->assertNull(Translations::scriptLocaleData());
    }

    public function test_script_locale_data_carries_singulars_through_the_fallback_and_plurals_as_is(): void
    {
        $this->domains['taw-core'] = self::catalog([
            'Remove'         => ['Quitar'],
            "verb\4Clear"    => ['Borrar'],
            '%d block'       => ['%d bloque', '%d bloques'],
        ]);
        Functions\when('determine_locale')->justReturn('es_MX');
        Functions\when('translate')->alias(static fn (string $text) => ['Remove' => 'Eliminar'][$text] ?? $text);
        Functions\when('translate_with_gettext_context')->alias(static fn (string $text, string $context) => $context === 'verb' ? 'Borrar' : $text);

        $this->assertSame([
            ''            => ['domain' => 'taw-core', 'lang' => 'es_MX'],
            'Remove'      => ['Eliminar'],
            "verb\4Clear" => ['Borrar'],
            '%d block'    => ['%d bloque', '%d bloques'],
        ], Translations::scriptLocaleData());
    }

    public function test_the_bundled_spanish_file_is_what_wordpress_loads(): void
    {
        $file = \dirname(__DIR__, 4) . '/languages/taw-core-es_MX.l10n.php';
        $data = include $file;

        $this->assertSame('taw-core', $data['domain']);
        $this->assertSame('es_MX', $data['language']);
        $this->assertSame('Agregar Renglón', $data['messages']['Add Row']);
        $this->assertGreaterThanOrEqual(21, count($data['messages']));
    }
}
