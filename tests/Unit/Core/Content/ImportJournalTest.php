<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Content;

use TAW\Core\Content\ImportJournal;
use TAW\Core\Content\RecordStore;
use TAW\Tests\TestCase;

/**
 * Content Interchange fidelity, phase 7: the journal keeps what an import
 * changed, and undo reverses exactly that, keeping later edits.
 */
final class ImportJournalTest extends TestCase
{
    private InMemoryRecords $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->store = new InMemoryRecords([
            'post:5'           => ['fields' => ['post_title' => 'Home', 'post_content' => 'Old'], 'meta' => ['_taw_hero' => [12]], 'terms' => ['category' => [3]]],
            'post:6'           => ['fields' => ['post_title' => 'About', 'post_content' => 'A'], 'meta' => []],
            'option:blogname'  => ['fields' => ['exists' => true, 'value' => 'Site']],
            'term:9'           => ['fields' => ['taxonomy' => 'category', 'slug' => 'old', 'name' => 'Old'], 'meta' => [], 'objects' => [5]],
        ]);
    }

    /** An import: edits post 5 and the site name, creates post 7, deletes term 9. */
    private function import(): array
    {
        $journal = new ImportJournal($this->store);
        $journal->touch('post', 5);
        $this->store->set('post:5', ['fields' => ['post_title' => 'Home', 'post_content' => 'New'], 'meta' => ['_taw_hero' => [40], '_taw_cta' => ['x']], 'terms' => ['category' => [3, 4]]]);
        $journal->touch('post', 5); // a second touch keeps the first capture
        $journal->touch('option', 'blogname');
        $this->store->set('option:blogname', ['fields' => ['exists' => true, 'value' => 'Prod site']]);
        $journal->touch('post', 7); // about to be inserted: nothing yet
        $this->store->set('post:7', ['fields' => ['post_title' => 'New page'], 'meta' => []]);
        $journal->created('post', 7);
        $journal->touch('term', 9, true);
        $this->store->remove('term:9');
        $journal->touch('post', 6); // touched, unchanged

        return $journal->entries();
    }

    public function test_the_journal_keeps_only_what_changed(): void
    {
        $entries = $this->import();

        $byId = array_column($entries, null, 'id');
        $this->assertSame(['fields.post_content', 'meta._taw_cta', 'meta._taw_hero', 'terms.category'], array_keys($byId[5]['changes']));
        $this->assertSame(['before' => null, 'after' => ['x']], $byId[5]['changes']['meta._taw_cta']);
        $this->assertSame('deleted', $byId[9]['action']);
        $this->assertSame([5], $byId[9]['before']['objects'], 'a deleted term keeps its posts');
        $this->assertSame('created', $byId[7]['action']);
        $this->assertArrayNotHasKey(6, $byId, 'a record touched but unchanged has no entry');
        $this->assertSame(['blogname'], array_values(array_filter(array_column($entries, 'id'), 'is_string')));
    }

    public function test_undo_reverses_it(): void
    {
        $entries = $this->import();

        $result = ImportJournal::undo($entries, $this->store);

        $this->assertSame(['restored' => 5, 'deleted' => 1, 'recreated' => 1, 'kept' => []], $result);
        $this->assertSame(['fields' => ['post_title' => 'Home', 'post_content' => 'Old'], 'meta' => ['_taw_hero' => [12]], 'terms' => ['category' => [3]]], $this->store->get('post:5'));
        $this->assertSame('Site', $this->store->get('option:blogname')['fields']['value']);
        $this->assertNull($this->store->get('post:7'));
        $this->assertSame('old', $this->store->recreated[0]['fields']['slug']);
    }

    public function test_edits_made_since_are_kept(): void
    {
        $entries = $this->import();
        $this->store->set('post:5', ['fields' => ['post_title' => 'Home', 'post_content' => 'Edited by hand'], 'meta' => ['_taw_hero' => [40], '_taw_cta' => ['x']], 'terms' => ['category' => [3, 4]]]);
        $this->store->set('post:7', ['fields' => ['post_title' => 'New page, edited'], 'meta' => []]);

        $result = ImportJournal::undo($entries, $this->store);

        $this->assertSame('Edited by hand', $this->store->get('post:5')['fields']['post_content'], 'the edit stays');
        $this->assertSame([12], $this->store->get('post:5')['meta']['_taw_hero'], 'the values not edited since go back');
        $this->assertNotNull($this->store->get('post:7'), 'a created page edited since stays');
        $this->assertSame(['post:5 fields.post_content (edited since)', 'post:7 (created by the import, edited since)'], $result['kept']);
    }

    public function test_undoing_twice_changes_nothing_more(): void
    {
        $entries = $this->import();
        ImportJournal::undo($entries, $this->store);
        $after = $this->store->all();

        $result = ImportJournal::undo($entries, $this->store);

        $this->assertSame($after, $this->store->all());
        $this->assertSame(['restored' => 0, 'deleted' => 0, 'recreated' => 0, 'kept' => []], $result);
    }
}

/** A {@see RecordStore} in memory: captures keyed "kind:id". */
final class InMemoryRecords implements RecordStore
{
    /** @var list<array<string, mixed>> */
    public array $recreated = [];

    /** @param array<string, array<string, mixed>> $records */
    public function __construct(private array $records)
    {
    }

    public function set(string $key, array $capture): void
    {
        $this->records[$key] = $capture;
    }

    public function remove(string $key): void
    {
        unset($this->records[$key]);
    }

    public function get(string $key): ?array
    {
        return $this->records[$key] ?? null;
    }

    public function all(): array
    {
        return $this->records;
    }

    public function capture(string $kind, int|string $id, bool $objects): ?array
    {
        $capture = $this->records["{$kind}:{$id}"] ?? null;
        if ($capture !== null && !$objects) {
            unset($capture['objects']);
        }

        return $capture;
    }

    public function restore(string $kind, int|string $id, array $values): void
    {
        foreach ($values as $path => $value) {
            [$section, $key] = explode('.', $path, 2);
            if ($value === null) {
                unset($this->records["{$kind}:{$id}"][$section][$key]);
            } else {
                $this->records["{$kind}:{$id}"][$section][$key] = $value;
            }
        }
    }

    public function delete(string $kind, int|string $id): void
    {
        unset($this->records["{$kind}:{$id}"]);
    }

    public function recreate(string $kind, array $capture): bool
    {
        foreach ($this->records as $record) {
            if (($record['fields']['slug'] ?? null) === ($capture['fields']['slug'] ?? '') && ($record['fields']['taxonomy'] ?? null) === ($capture['fields']['taxonomy'] ?? '')) {
                return false;
            }
        }
        $this->recreated[] = $capture;
        $this->records[$kind . ':new' . count($this->recreated)] = $capture;

        return true;
    }
}
