<?php

namespace App\Services;

use App\Exceptions\UnauthorizedVaultAccessException;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\ProductVault;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class VaultEncryptionService
{
    public function computeBlindHash(string $rawPayload): string
    {
        return hash_hmac('sha256', strtolower(trim($rawPayload)), (string) config('app.key'));
    }

    /**
     * @param  list<string>  $rawLines
     * @return array{
     *   product_id: int,
     *   inserted_count: int,
     *   duplicate_count: int,
     *   skipped_empty_count: int,
     *   current_available_stock: int,
     *   inserted_items: list<array{id: int, masked_preview: string, blind_hash: string}>,
     *   rejected_duplicates: list<array{masked_preview: string, blind_hash: string, reason: string}>
     * }
     */
    public function bulkIngestVaultItems(int $productId, int $sellerId, array $rawLines): array
    {
        return DB::transaction(function () use ($productId, $sellerId, $rawLines): array {
            $productRow = DB::table('products')
                ->where('id', $productId)
                ->lockForUpdate()
                ->first();

            if (! $productRow) {
                throw new DomainException("Produk #{$productId} tidak ditemukan.");
            }

            $actor = User::find($sellerId);
            $isAdmin = $actor?->isAdmin() ?? false;

            if ((int) $productRow->seller_id !== $sellerId && ! $isAdmin) {
                throw new UnauthorizedVaultAccessException('Anda tidak memiliki izin mengisi brankas produk milik toko lain.');
            }

            $category = DB::table('game_categories')->where('id', $productRow->game_category_id)->first();
            if (! $category || $category->delivery_method !== 'instant_vault') {
                throw new DomainException('Brankas AES-256 hanya berlaku untuk produk dengan metode pengiriman instant_vault.');
            }

            $normalizedEntries = [];
            $skippedEmpty = 0;

            foreach ($rawLines as $line) {
                $trimmed = trim((string) $line);
                if ($trimmed === '') {
                    $skippedEmpty++;

                    continue;
                }

                $normalizedEntries[] = [
                    'raw' => $trimmed,
                    'hash' => $this->computeBlindHash($trimmed),
                ];
            }

            $candidateHashes = array_values(array_unique(array_column($normalizedEntries, 'hash')));
            $existingHashes = [];

            if (! empty($candidateHashes)) {
                $existingHashes = DB::table('product_vaults')
                    ->whereIn('payload_blind_hash', $candidateHashes)
                    ->pluck('payload_blind_hash')
                    ->all();
            }

            $existingLookup = array_flip($existingHashes);
            $seenInBatch = [];
            $insertedItems = [];
            $rejectedDuplicates = [];

            foreach ($normalizedEntries as $entry) {
                $hash = $entry['hash'];
                $raw = $entry['raw'];
                $masked = $this->maskPayload($raw);

                if (isset($existingLookup[$hash])) {
                    $rejectedDuplicates[] = [
                        'masked_preview' => $masked,
                        'blind_hash' => $hash,
                        'reason' => 'Duplikat terdeteksi via HMAC-SHA256 Blind Index (sudah ada di brankas).',
                    ];

                    continue;
                }

                if (isset($seenInBatch[$hash])) {
                    $rejectedDuplicates[] = [
                        'masked_preview' => $masked,
                        'blind_hash' => $hash,
                        'reason' => 'Duplikat di dalam batch upload yang sama.',
                    ];

                    continue;
                }

                $seenInBatch[$hash] = true;

                $vault = ProductVault::create([
                    'product_id' => $productId,
                    'payload_blind_hash' => $hash,
                    'encrypted_payload' => Crypt::encryptString($raw),
                    'status' => 'available',
                ]);

                $insertedItems[] = [
                    'id' => $vault->id,
                    'masked_preview' => $masked,
                    'blind_hash' => $hash,
                ];
            }

            $availableStock = $this->syncVaultStockWithinLock($productId);

            return [
                'product_id' => $productId,
                'inserted_count' => count($insertedItems),
                'duplicate_count' => count($rejectedDuplicates),
                'skipped_empty_count' => $skippedEmpty,
                'current_available_stock' => $availableStock,
                'inserted_items' => $insertedItems,
                'rejected_duplicates' => $rejectedDuplicates,
            ];
        });
    }

    public function syncVaultStockWithinLock(int $productId): int
    {
        $availableCount = (int) DB::table('product_vaults')
            ->where('product_id', $productId)
            ->where('status', 'available')
            ->count();

        DB::table('products')
            ->where('id', $productId)
            ->update([
                'stock' => $availableCount,
                'updated_at' => now(),
            ]);

        return $availableCount;
    }

    public function decryptForAuthorizedBuyer(Order $order, OrderItem $item, int $requestingUserId): string
    {
        if ((int) $item->order_id !== (int) $order->id) {
            throw new UnauthorizedVaultAccessException('Item pesanan tidak cocok dengan nomor pesanan.');
        }

        $requester = User::find($requestingUserId);
        $isAuthorized = ((int) $order->buyer_id === $requestingUserId) || ($requester?->isAdmin() ?? false);

        if (! $isAuthorized) {
            throw new UnauthorizedVaultAccessException('Akses ditolak: hanya pembeli pemilik pesanan atau admin sengketa yang boleh membuka brankas AES-256.');
        }

        if (! in_array($order->status, ['DELIVERED', 'COMPLETED', 'DISPUTED'], true)) {
            throw new UnauthorizedVaultAccessException("Brankas belum dapat dibuka pada status pesanan [{$order->status}].");
        }

        if (empty($item->encrypted_delivery_payload)) {
            throw new DomainException('Payload brankas terenkripsi tidak ditemukan pada item pesanan ini.');
        }

        return Crypt::decryptString($item->encrypted_delivery_payload);
    }

    public function maskPayload(string $raw): string
    {
        $length = mb_strlen($raw);
        if ($length <= 6) {
            return str_repeat('•', $length);
        }

        return mb_substr($raw, 0, 4).str_repeat('•', min(12, $length - 6)).mb_substr($raw, -2);
    }
}
