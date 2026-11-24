<?php

namespace App\Data;

final readonly class BulkVaultImportResult
{
    public function __construct(
        public int $totalLinesProcessed,
        public int $insertedCount,
        public int $skippedDuplicateInBatchCount,
        public int $skippedDuplicateInDbCount,
        public int $skippedEmptyCount,
    ) {}
}
