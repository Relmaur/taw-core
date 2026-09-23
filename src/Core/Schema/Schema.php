<?php

declare(strict_types=1);

namespace TAW\Core\Schema;

use TAW\Core\Schema\Definition\EditingPolicy;
use TAW\Core\Schema\Definition\Fieldset;
use TAW\Core\Schema\Definition\OptionsPage;
use TAW\Core\Schema\Definition\PostType;
use TAW\Core\Schema\Definition\Taxonomy;

// No ABSPATH guard: pure class, loadable by bin/taw before WordPress boots.

/**
 * Entry point for defining data in PHP (ADR-0004).
 *
 *   use TAW\Core\Schema\{Schema, Field, Registry};
 *
 *   add_action('taw_schema_register', function (Registry $schema): void {
 *       $schema->add(Schema::postType('book')->labels('Book', 'Books'));
 *       $schema->add(Schema::taxonomy('genre')->for('book')->labels('Genre', 'Genres'));
 *       $schema->add(
 *           Schema::fieldset('book_details')->title('Book details')->on('book')->fields([
 *               Field::text('subtitle')->label('Subtitle'),
 *               Field::image('cover')->label('Cover'),
 *           ])
 *       );
 *   });
 *
 * The action fires on init (priority 1), so __() is safe inside it. Nothing
 * reaches WordPress until the Compiler registers the definitions a few
 * priorities later; adding a definition after that (init:5) is refused with
 * a _doing_it_wrong() notice.
 */
final class Schema
{
    public static function postType(string $key): PostType
    {
        return new PostType($key);
    }

    public static function taxonomy(string $key): Taxonomy
    {
        return new Taxonomy($key);
    }

    public static function fieldset(string $key): Fieldset
    {
        return new Fieldset($key);
    }

    public static function optionsPage(string $key): OptionsPage
    {
        return new OptionsPage($key);
    }

    /**
     * The site's editing policy (ADR-0005). There's one per site, so the key
     * is always "site".
     */
    public static function editing(): EditingPolicy
    {
        return new EditingPolicy(EditingPolicy::KEY);
    }
}
