<?php

namespace App\Services;

use App\Data\BulkVaultImportResult;
use App\Exceptions\UnauthorizedVaultAccessException;
use App\Models\Product;
use App\Models\ProductVault;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VaultBulkImportService
{
    public const MAX_NON_EMPTY_LINES = 500;

    public function __construct(
        private readonly VaultEncryptionService $vaultEncryptionService,
    ) {}

    /**
     * Deduplicate, AES-256 encrypt, and bulk-insert up to 500 vault lines in a single atomic transaction.
     *
     * @param  array<int, string>  $rawLines
     */
    public function importLines(int $sellerId, int $productId, array $rawLines): BulkVaultImportResult
    {
        $totalLinesProcessed = count($rawLines);
        $skippedEmptyCount = 0;
        $nonEmptyLines = [];

        foreach ($rawLines as $idx => $rawLine) {
            $line = (string) $rawLine;
            if ($idx === 0 && str_starts_with($line, "\xEF\xBB\xBF")) {
                $line = substr($line, 3);
            }
            $line = str_replace(["\xEF\xBB\xBF", "\r"], '', $line);
            $trimmed = trim($line);

            if ($trimmed === '') {
                $skippedEmptyCount++;

                continue;
            }

            $nonEmptyLines[] = $trimmed;
        }

        if (count($nonEmptyLines) > self::MAX_NON_EMPTY_LINES) {
            throw ValidationException::withMessages([
                'vault_codes_text' => [
                    'Maksimal '.self::MAX_NON_EMPTY_LINES.' baris kode non-kosong yang dapat diimpor dalam satu transaksi (dikirim: '.count($nonEmptyLines).' baris).',
                ],
            ]);
        }

        $seenInBatch = [];
        $uniqueBatchEntries = [];
        $skippedDuplicateInBatchCount = 0;

        foreach ($nonEmptyLines as $cleanLine) {
            $blindHash = $this->vaultEncryptionService->computeBlindHash($cleanLine);
            if (isset($seenInBatch[$blindHash])) {
                $skippedDuplicateInBatchCount++;

                continue;
            }

            $seenInBatch[$blindHash] = true;
            $uniqueBatchEntries[] = [
                'raw' => $cleanLine,
                'hash' => $blindHash,
            ];
        }

        return DB::transaction(function () use (
            $sellerId,
            $productId,
            $totalLinesProcessed,
            $skippedEmptyCount,
            $skippedDuplicateInBatchCount,
            $uniqueBatchEntries,
        ): BulkVaultImportResult {
            $product = Product::with('gameCategory')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $product) {
                throw new DomainException("Produk #{$productId} tidak ditemukan.");
            }

            $actor = User::find($sellerId);
            $isAdmin = $actor?->isAdmin() ?? false;

            if ((int) $product->seller_id !== $sellerId && ! $isAdmin) {
                throw new UnauthorizedVaultAccessException('Anda tidak memiliki izin mengisi brankas produk milik toko lain.');
            }

            if ($product->gameCategory?->delivery_method !== 'instant_vault') {
                throw new DomainException('Bulk import brankas AES-256 hanya berlaku untuk produk dengan metode pengiriman instant_vault.');
            }

            $candidateHashes = array_column($uniqueBatchEntries, 'hash');
            $existingHashes = $candidateHashes === []
                ? []
                : ProductVault::whereIn('payload_blind_hash', $candidateHashes)
                    ->pluck('payload_blind_hash')
                    ->all();

            $existingLookup = array_flip($existingHashes);
            $skippedDuplicateInDbCount = 0;
            $rowsToInsert = [];
            $now = now();

            foreach ($uniqueBatchEntries as $entry) {
                if (isset($existingLookup[$entry['hash']])) {
                    $skippedDuplicateInDbCount++;

                    continue;
                }

                $rowsToInsert[] = [
                    'product_id' => $product->id,
                    'payload_blind_hash' => $entry['hash'],
                    'encrypted_payload' => Crypt::encryptString($entry['raw']),
                    'status' => 'available',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rowsToInsert !== []) {
                ProductVault::insert($rowsToInsert);
            }

            $this->vaultEncryptionService->syncVaultStockWithinLock((int) $product->id);

            return new BulkVaultImportResult(
                totalLinesProcessed: $totalLinesProcessed,
                insertedCount: count($rowsToInsert),
                skippedDuplicateInBatchCount: $skippedDuplicateInBatchCount,
                skippedDuplicateInDbCount: $skippedDuplicateInDbCount,
                skippedEmptyCount: $skippedEmptyCount,
            );
        });
    }
}
