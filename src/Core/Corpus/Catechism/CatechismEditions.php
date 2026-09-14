<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\Catechism;

/**
 * The slug → filename/display-name registry every Catechism reader,
 * installer, and REST route resolves an `$edition` argument through.
 *
 * This is the one place "which catechism editions exist" is declared —
 * {@see CatechismReader}/{@see MysqlCatechismReader}/
 * {@see \TAW\Core\Rest\CatechismEndpoint} never hardcode a filename or
 * display name themselves. Adding a second edition (e.g. the Catechism of
 * Saint John Paul II) is one new array entry plus installing its own
 * `.sqlite` file — no other code in this namespace changes.
 *
 * Deliberately a plain static array, not `wp_options`/admin-configurable:
 * same posture as {@see \TAW\Core\Corpus\Bible\BibleReader::FILENAME} —
 * a developer-curated dataset a developer registers, not something an
 * end-user adds through a web form.
 */
final class CatechismEditions
{
    /**
     * @var array<string, array{filename: string, name: string}>
     */
    private const EDITIONS = [
        'pius-x' => [
            'filename' => 'catechism-pius-x.sqlite',
            'name' => 'Catecismo Mayor de San Pío X',
        ],
    ];

    public static function filename(string $slug): ?string
    {
        return self::EDITIONS[$slug]['filename'] ?? null;
    }

    public static function name(string $slug): ?string
    {
        return self::EDITIONS[$slug]['name'] ?? null;
    }

    public static function exists(string $slug): bool
    {
        return isset(self::EDITIONS[$slug]);
    }

    /**
     * @return list<array{slug: string, name: string}>
     */
    public static function all(): array
    {
        $result = [];
        foreach (self::EDITIONS as $slug => $edition) {
            $result[] = ['slug' => $slug, 'name' => $edition['name']];
        }

        return $result;
    }
}
