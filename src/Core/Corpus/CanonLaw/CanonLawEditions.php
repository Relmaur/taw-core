<?php

declare(strict_types=1);

namespace TAW\Core\Corpus\CanonLaw;

/**
 * The slug → filename/display-name registry every Canon Law reader,
 * installer, and REST route resolves an `$edition` argument through —
 * same posture as {@see \TAW\Core\Corpus\Catechism\CatechismEditions}: a
 * plain, developer-curated static array, not `wp_options`. Adding the
 * 1917 Pio-Benedictine Code later is one new entry plus installing its
 * own `.sqlite` file.
 */
final class CanonLawEditions
{
    /**
     * The `meta.surface` value every canon-law export carries — what
     * {@see \TAW\CLI\CanonLawInstallCommand} checks before installing a
     * file, so a Bible or Catechism export can never be installed as a
     * Code by mistake.
     */
    public const SURFACE = 'canon_law';

    /**
     * @var array<string, array{filename: string, name: string}>
     */
    private const EDITIONS = [
        'cic-1983' => [
            'filename' => 'canon-law-cic-1983.sqlite',
            'name' => 'Código de Derecho Canónico (1983)',
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
