<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Tools;

use TAW\Core\Rag\Reference\BibleRepository;
use TAW\Core\Rag\Storage;

if (!defined('ABSPATH')) {
    exit;
}

final class BibleLookupTool implements RagTool
{
    public function name(): string
    {
        return 'lookup_bible';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Look up a Bible verse or an entire chapter (Straubinger translation).',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'book' => ['type' => 'string', 'description' => 'Book name, e.g. "Genesis" or "John".'],
                        'chapter' => ['type' => 'string', 'description' => 'Chapter number.'],
                        'verse' => ['type' => 'string', 'description' => 'Verse number. Omit to return the whole chapter.'],
                    ],
                    'required' => ['book', 'chapter'],
                ],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $book = trim((string) ($arguments['book'] ?? ''));
        $chapter = trim((string) ($arguments['chapter'] ?? ''));
        $verse = isset($arguments['verse']) ? trim((string) $arguments['verse']) : null;

        if ($book === '' || $chapter === '') {
            return ['error' => 'book and chapter are required.'];
        }

        Storage::ensureProtectedDir(Storage::dir());
        $repository = new BibleRepository(Storage::openSqlite(Storage::dbPath('bible_straubinger.sqlite')));
        $results = $repository->lookup($book, $chapter, $verse);

        return $results === [] ? ['error' => 'No matching verse(s) found.'] : ['results' => $results];
    }
}
