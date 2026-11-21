<?php

namespace App\Services;

use App\Exceptions\SelfDealingException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    public function __construct(
        private readonly ChatModerationService $chatModerationService,
        private readonly NotificationService $notificationService,
    ) {}

    public function startOrGetConversation(
        int $buyerId,
        int $sellerId,
        ?int $productId = null,
        ?int $orderId = null,
    ): Conversation {
        if ($buyerId === $sellerId) {
            throw new SelfDealingException('Anda tidak dapat memulai percakapan dengan toko milik sendiri.');
        }

        $sellerExists = SellerProfile::where('user_id', $sellerId)->exists();
        if (! $sellerExists) {
            throw new DomainException('Toko penjual tujuan tidak ditemukan.');
        }

        if ($productId !== null) {
            $productMatchesSeller = Product::where('id', $productId)
                ->where('seller_id', $sellerId)
                ->exists();
            if (! $productMatchesSeller) {
                $productId = null;
            }
        }

        return DB::transaction(function () use ($buyerId, $sellerId, $productId, $orderId): Conversation {
            $conversation = Conversation::where('buyer_id', $buyerId)
                ->where('seller_id', $sellerId)
                ->lockForUpdate()
                ->first();

            if ($conversation) {
                $dirty = false;
                if ($productId !== null && (int) $conversation->context_product_id !== $productId) {
                    $conversation->context_product_id = $productId;
                    $dirty = true;
                }
                if ($orderId !== null && (int) $conversation->context_order_id !== $orderId) {
                    $conversation->context_order_id = $orderId;
                    $dirty = true;
                }
                if ($dirty) {
                    $conversation->save();
                }

                return $conversation->fresh(['buyer', 'seller', 'sellerProfile', 'contextProduct']);
            }

            return Conversation::create([
                'buyer_id' => $buyerId,
                'seller_id' => $sellerId,
                'context_product_id' => $productId,
                'context_order_id' => $orderId,
                'buyer_unread_count' => 0,
                'seller_unread_count' => 0,
                'last_message_preview' => null,
                'last_message_at' => now(),
            ])->load(['buyer', 'seller', 'sellerProfile', 'contextProduct']);
        });
    }

    public function sendMessage(
        int $conversationId,
        int $senderId,
        string $rawMessage,
        ?int $attachedProductId = null,
        ?int $attachedOrderId = null,
    ): ConversationMessage {
        $trimmed = trim($rawMessage);
        if ($trimmed === '') {
            throw new DomainException('Pesan tidak boleh kosong.');
        }

        return DB::transaction(function () use ($conversationId, $senderId, $trimmed, $attachedProductId, $attachedOrderId): ConversationMessage {
            $conversation = Conversation::where('id', $conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                throw new DomainException('Percakapan tidak ditemukan.');
            }

            $isBuyer = (int) $conversation->buyer_id === $senderId;
            $isSeller = (int) $conversation->seller_id === $senderId;

            if (! $isBuyer && ! $isSeller) {
                throw new AuthorizationException('Anda tidak memiliki akses ke percakapan ini.');
            }

            $senderRole = $isBuyer ? 'buyer' : 'seller';
            $recipientId = $isBuyer ? (int) $conversation->seller_id : (int) $conversation->buyer_id;

            if ($attachedProductId !== null) {
                $validProduct = Product::withTrashed()
                    ->where('id', $attachedProductId)
                    ->where('seller_id', $conversation->seller_id)
                    ->exists();
                if (! $validProduct) {
                    $attachedProductId = null;
                }
            }

            $moderated = $this->chatModerationService->moderate($trimmed);
            $now = now();

            $message = ConversationMessage::create([
                'conversation_id' => $conversation->id,
                'sender_id' => $senderId,
                'sender_role' => $senderRole,
                'attached_product_id' => $attachedProductId,
                'attached_order_id' => $attachedOrderId,
                'raw_message' => $moderated['raw_message'],
                'sanitized_message' => $moderated['sanitized_message'],
                'is_flagged_bypass' => $moderated['is_flagged_bypass'],
                'detected_violation_type' => $moderated['detected_violation_type'],
                'read_at' => null,
                'created_at' => $now,
            ]);

            $preview = mb_substr($moderated['sanitized_message'], 0, 155);
            $conversation->last_message_preview = $preview;
            $conversation->last_message_at = $now;

            if ($attachedProductId !== null) {
                $conversation->context_product_id = $attachedProductId;
            }
            if ($attachedOrderId !== null) {
                $conversation->context_order_id = $attachedOrderId;
            }

            if ($isBuyer) {
                $conversation->seller_unread_count = (int) $conversation->seller_unread_count + 1;
            } else {
                $conversation->buyer_unread_count = (int) $conversation->buyer_unread_count + 1;
            }
            $conversation->save();

            $sender = User::with('sellerProfile')->find($senderId);
            $senderLabel = $isSeller && $sender?->sellerProfile
                ? $sender->sellerProfile->store_name
                : ($sender?->name ?? 'Pengguna NexaTrade');

            $this->notificationService->notify(
                userId: $recipientId,
                category: 'chat',
                title: "Pesan baru dari {$senderLabel}",
                body: $preview,
                actionUrl: route('inbox.index', ['conversation' => $conversation->id], false),
                refType: 'conversation',
                refId: (int) $conversation->id,
            );

            return $message->load(['sender', 'attachedProduct.images', 'attachedProduct.gameCategory.game']);
        });
    }

    public function markConversationRead(int $conversationId, int $viewerId): void
    {
        DB::transaction(function () use ($conversationId, $viewerId): void {
            $conversation = Conversation::where('id', $conversationId)->lockForUpdate()->first();
            if (! $conversation) {
                throw new DomainException('Percakapan tidak ditemukan.');
            }

            $isBuyer = (int) $conversation->buyer_id === $viewerId;
            $isSeller = (int) $conversation->seller_id === $viewerId;

            if (! $isBuyer && ! $isSeller) {
                throw new AuthorizationException('Anda tidak memiliki akses ke percakapan ini.');
            }

            ConversationMessage::where('conversation_id', $conversation->id)
                ->where('sender_id', '!=', $viewerId)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);

            if ($isBuyer) {
                $conversation->buyer_unread_count = 0;
            } else {
                $conversation->seller_unread_count = 0;
            }
            $conversation->save();
        });
    }

    public function getTotalUnreadCount(int $userId): int
    {
        $asBuyer = (int) Conversation::where('buyer_id', $userId)->sum('buyer_unread_count');
        $asSeller = (int) Conversation::where('seller_id', $userId)->sum('seller_unread_count');

        return $asBuyer + $asSeller;
    }
}
