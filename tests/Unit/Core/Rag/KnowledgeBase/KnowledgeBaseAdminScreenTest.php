<?php

declare(strict_types=1);

namespace TAW\Tests\Unit\Core\Rag\KnowledgeBase;

use TAW\Core\Rag\KnowledgeBase\KnowledgeBaseAdminScreen;
use TAW\Tests\TestCase;

final class KnowledgeBaseAdminScreenTest extends TestCase
{
    private function tempFile(string $contents): string
    {
        $path = sys_get_temp_dir() . '/taw-rag-kb-upload-' . uniqid();
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_accepts_a_real_sqlite_file(): void
    {
        $path = $this->tempFile('');
        (new \PDO('sqlite:' . $path))->exec('CREATE TABLE t (a TEXT)');

        $result = $this->callMethod(new KnowledgeBaseAdminScreen(), 'looksLikeSqlite', $path);

        @unlink($path);
        $this->assertTrue($result);
    }

    public function test_rejects_a_non_sqlite_file(): void
    {
        $path = $this->tempFile('not a database, just text');

        $result = $this->callMethod(new KnowledgeBaseAdminScreen(), 'looksLikeSqlite', $path);

        @unlink($path);
        $this->assertFalse($result);
    }

    public function test_rejects_an_empty_file(): void
    {
        $path = $this->tempFile('');

        $result = $this->callMethod(new KnowledgeBaseAdminScreen(), 'looksLikeSqlite', $path);

        @unlink($path);
        $this->assertFalse($result);
    }
}
