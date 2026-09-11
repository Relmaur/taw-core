<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\KnowledgeBase;

use PDO;
use TAW\Core\Rag\KnowledgeBase\GenericSqliteIngestor;
use TAW\Tests\TestCase;

final class GenericSqliteIngestorTest extends TestCase
{
    public function test_extracts_column_labelled_text_from_a_table_with_text_columns(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE verses (id INTEGER PRIMARY KEY, book TEXT, chapter INTEGER, text TEXT)');
        $pdo->exec("INSERT INTO verses (book, chapter, text) VALUES ('Genesis', 1, 'In the beginning...')");

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('book: Genesis', $rows[0]);
        $this->assertStringContainsString('text: In the beginning...', $rows[0]);
        // "chapter" has INTEGER affinity — not a text column, excluded.
        $this->assertStringNotContainsString('chapter:', $rows[0]);
    }

    public function test_skips_a_table_with_no_text_affinity_columns(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE numbers_only (id INTEGER PRIMARY KEY, value INTEGER, ratio REAL)');
        $pdo->exec('INSERT INTO numbers_only (value, ratio) VALUES (1, 1.5)');

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertSame([], $rows);
    }

    public function test_columns_with_no_declared_type_are_treated_as_text(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE loose (id INTEGER PRIMARY KEY, note)');
        $pdo->exec("INSERT INTO loose (note) VALUES ('hello world')");

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('note: hello world', $rows[0]);
    }

    public function test_null_and_empty_values_are_omitted(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (a TEXT, b TEXT)');
        $pdo->exec("INSERT INTO t (a, b) VALUES ('present', NULL)");
        $pdo->exec("INSERT INTO t (a, b) VALUES ('', 'also present')");

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertCount(2, $rows);
        $this->assertSame('a: present', $rows[0]);
        $this->assertSame('b: also present', $rows[1]);
    }

    public function test_a_row_with_only_empty_values_is_omitted_entirely(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE t (a TEXT)');
        $pdo->exec("INSERT INTO t (a) VALUES (NULL)");

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertSame([], $rows);
    }

    public function test_the_vector_table_itself_is_excluded_from_extraction(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE taw_rag_chunks (content TEXT)');
        $pdo->exec("INSERT INTO taw_rag_chunks (content) VALUES ('should not be re-ingested')");
        $pdo->exec('CREATE TABLE real_data (content TEXT)');
        $pdo->exec("INSERT INTO real_data (content) VALUES ('should be ingested')");

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('should be ingested', $rows[0]);
    }

    public function test_handles_multiple_tables(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE a (x TEXT)');
        $pdo->exec("INSERT INTO a (x) VALUES ('from table a')");
        $pdo->exec('CREATE TABLE b (y TEXT)');
        $pdo->exec("INSERT INTO b (y) VALUES ('from table b')");

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertCount(2, $rows);
    }

    public function test_table_and_column_names_needing_quoting_are_handled_safely(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE "weird table" ("a column" TEXT)');
        $pdo->exec('INSERT INTO "weird table" ("a column") VALUES (\'value\')');

        $rows = (new GenericSqliteIngestor())->extractRows($pdo);

        $this->assertCount(1, $rows);
        $this->assertStringContainsString('value', $rows[0]);
    }
}
