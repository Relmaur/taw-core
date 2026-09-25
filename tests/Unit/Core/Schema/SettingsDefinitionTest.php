<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Schema;

use TAW\Core\Metabox\Metabox;
use TAW\Core\Schema\Definition\SiteSettings;
use TAW\Core\Schema\Field;
use TAW\Core\Schema\JsonLoader;
use TAW\Core\Schema\Registry;
use TAW\Core\Schema\Schema;
use TAW\Core\Schema\Validator;

/**
 * The `settings` kind and a fieldset's `ui` (ADR-0007 § 1).
 */
final class SettingsDefinitionTest extends SchemaTestCase
{
    public function test_settings_builder_and_json_shape(): void
    {
        $this->assertSame([], Schema::settings()->toArray());
        $this->assertSame(['fieldsetUi' => 'panel'], Schema::settings()->fieldsetUi('panel')->toArray());
        $this->assertSame('settings:site', Schema::settings()->qualifiedKey());
        $this->assertSame([], Schema::settings()->fieldsetUi('metabox')->problems());
        $this->assertSame(['Site settings /fieldsetUi: must be one of panel, metabox'], Schema::settings()->fieldsetUi('sidebar')->problems());
    }

    public function test_the_key_must_be_site(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SiteSettings('main');
    }

    public function test_validator_and_loader(): void
    {
        $data = ['version' => 1, 'kind' => 'settings', 'key' => 'site', 'fieldsetUi' => 'panel'];

        $this->assertSame([], Validator::validate($data));
        $this->assertSame(['/key: must be "site" (a site has one settings definition)'], Validator::validate(['key' => 'x'] + $data));
        $this->assertSame(['/fieldsetUi: must be one of panel, metabox'], Validator::validate(['fieldsetUi' => 'side'] + $data));
        $this->assertSame(['/ui: unknown key for a settings (allowed: fieldsetUi)'], Validator::validate($data + ['ui' => 'panel']));

        $definition = JsonLoader::toDefinition($data);
        $this->assertInstanceOf(SiteSettings::class, $definition);
        $this->assertSame('panel', $definition->fieldsetUiValue());
    }

    public function test_registry_holds_one_settings_definition(): void
    {
        $this->assertNull(Registry::instance()->settings());

        Registry::instance()->add(Schema::settings()->fieldsetUi('panel'));

        $this->assertSame('panel', Registry::instance()->settings()?->fieldsetUiValue());
    }

    public function test_fieldset_ui_reaches_the_metabox(): void
    {
        $fieldset = Schema::fieldset('book_details')->on('book')->ui('panel')->fields([Field::text('book_author')]);

        $this->assertSame([], $fieldset->problems());
        $this->assertSame('panel', $fieldset->toArray()['ui']);

        Metabox::forgetInstances();
        $this->assertSame('panel', (new Metabox($fieldset->toArray()))->ui());
        $this->assertNull((new Metabox(['id' => 'x', 'title' => 'X', 'fields' => []]))->ui());
    }

    public function test_fieldset_ui_is_validated_in_php_and_json(): void
    {
        $this->assertSame(
            ['Fieldset "book_details" ui must be one of panel, metabox.'],
            Schema::fieldset('book_details')->on('book')->ui('side')->fields([Field::text('a')])->problems()
        );

        $json = ['version' => 1, 'kind' => 'fieldset', 'key' => 'book_details', 'on' => ['book'], 'fields' => [['id' => 'a', 'type' => 'text']]];
        $this->assertSame([], Validator::validate($json + ['ui' => 'panel']));
        $this->assertSame(['/ui: must be one of panel, metabox'], Validator::validate($json + ['ui' => 'side']));
        $this->assertSame('panel', JsonLoader::toDefinition($json + ['ui' => 'panel'])->toArray()['ui']);
    }
}
