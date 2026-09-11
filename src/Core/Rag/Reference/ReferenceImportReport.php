<?php

declare(strict_types=1);

namespace TAW\Core\Rag\Reference;

final class ReferenceImportReport
{
    /**
     * @param list<string> $warnings
     */
    public function __construct(
        public readonly int $inserted,
        public readonly int $updated,
        public readonly int $skipped,
        public readonly array $warnings,
    ) {
    }

    /**
     * @return array{inserted: int, updated: int, skipped: int, warnings: list<string>}
     */
    public function toArray(): array
    {
        return [
            'inserted' => $this->inserted,
            'updated'  => $this->updated,
            'skipped'  => $this->skipped,
            'warnings' => $this->warnings,
        ];
    }
}
