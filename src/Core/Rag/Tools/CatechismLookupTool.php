<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Tools;

use TAW\Core\Rag\Reference\CatechismRepository;
use TAW\Core\Rag\Storage;

if (!defined('ABSPATH')) {
    exit;
}

final class CatechismLookupTool implements RagTool
{
    public function name(): string
    {
        return 'lookup_catechism';
    }

    public function definition(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => 'Look up a Catechism of Trent entry by "part/question" reference (e.g. "First Part/1") or by a free-text topic keyword.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'topic_or_number' => [
                            'type' => 'string',
                            'description' => 'A "part/question" reference, or a topic keyword to search for.',
                        ],
                    ],
                    'required' => ['topic_or_number'],
                ],
            ],
        ];
    }

    public function call(array $arguments): array
    {
        $topicOrNumber = trim((string) ($arguments['topic_or_number'] ?? ''));

        if ($topicOrNumber === '') {
            return ['error' => 'topic_or_number is required.'];
        }

        Storage::ensureProtectedDir(Storage::dir());
        $repository = new CatechismRepository(Storage::openSqlite(Storage::dbPath('catechism_trent.sqlite')));
        $results = $repository->lookup($topicOrNumber);

        return $results === [] ? ['error' => 'No matching entries found.'] : ['results' => $results];
    }
}
