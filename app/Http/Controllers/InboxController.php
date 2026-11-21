<?php

namespace App\Http\Controllers;

use App\Exceptions\SelfDealingException;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Product;
use App\Services\ConversationService;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboxController extends Controller
{
    public function __construct(
        private readonly ConversationService $conversationService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $search = trim($request->string('q')->toString());
        $requestedId = $request->integer('conversation');
        $preAttachProductId = $request->integer('attach_product');

        $conversationsQuery = Conversation::with([
            'buyer',
            'seller',
            'sellerProfile',
            'contextProduct.images',
            'contextProduct.gameCategory.game',
        ])
            ->where(function ($q) use ($user): void {
                $q->where('buyer_id', $user->id)
                    ->orWhere('seller_id', $user->id);
            });

        if ($search !== '') {
            $conversationsQuery->where(function ($q) use ($search): void {
                $q->whereHas('buyer', fn ($bq) => $bq->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('sellerProfile', fn ($sq) => $sq->where('store_name', 'like', "%{$search}%"))
                    ->orWhere('last_message_preview', 'like', "%{$search}%");
            });
        }

        $conversations = $conversationsQuery
            ->orderByDesc('last_message_at')
            ->orderByDesc('id')
            ->get();

        $activeConversation = null;
        if ($requestedId > 0) {
            $activeConversation = $conversations->firstWhere('id', $requestedId);
        }
        if (! $activeConversation && $conversations->isNotEmpty()) {
            $activeConversation = $conversations->first();
        }

        $messages = collect();
        $storeProducts = collect();
        $preAttachedProduct = null;

        if ($activeConversation) {
            $this->conversationService->markConversationRead($activeConversation->id, $user->id);
            $activeConversation->refresh()->load([
                'buyer',
                'seller',
                'sellerProfile',
                'contextProduct.images',
                'contextProduct.gameCategory.game',
            ]);

            $messages = $activeConversation->messages()
                ->with(['sender', 'attachedProduct.images', 'attachedProduct.gameCategory.game', 'attachedOrder'])
                ->get();

            $storeProducts = Product::with(['images', 'gameCategory.game'])
                ->where('seller_id', $activeConversation->seller_id)
                ->where('is_active', true)
                ->orderByDesc('is_featured')
                ->orderByDesc('sold_count')
                ->limit(15)
                ->get();

            if ($preAttachProductId > 0) {
                $preAttachedProduct = $storeProducts->firstWhere('id', $preAttachProductId);
            } elseif ($activeConversation->context_product_id && $messages->isEmpty()) {
                $preAttachedProduct = $storeProducts->firstWhere('id', $activeConversation->context_product_id);
            }
        }

        $serializedMessages = $messages->map(fn (ConversationMessage $msg): array => $this->serializeMessage($msg, $user->id))->values()->all();

        return view('inbox.index', compact(
            'user',
            'conversations',
            'activeConversation',
            'serializedMessages',
            'storeProducts',
            'preAttachedProduct',
            'search',
        ));
    }

    public function start(Request $request): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'seller_id' => ['required', 'integer', 'exists:users,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        try {
            $conversation = $this->conversationService->startOrGetConversation(
                buyerId: $request->user()->id,
                sellerId: (int) $validated['seller_id'],
                productId: isset($validated['product_id']) ? (int) $validated['product_id'] : null,
                orderId: isset($validated['order_id']) ? (int) $validated['order_id'] : null,
            );

            $redirectUrl = route('inbox.index', array_filter([
                'conversation' => $conversation->id,
                'attach_product' => $validated['product_id'] ?? null,
            ]));

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'conversation_id' => $conversation->id,
                    'redirect_url' => $redirectUrl,
                ]);
            }

            return redirect()->to($redirectUrl);
        } catch (SelfDealingException|DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    public function messages(Request $request, Conversation $conversation): JsonResponse
    {
        $user = $request->user();
        if ((int) $conversation->buyer_id !== (int) $user->id && (int) $conversation->seller_id !== (int) $user->id) {
            abort(403, 'Anda tidak memiliki akses ke percakapan ini.');
        }

        $this->conversationService->markConversationRead($conversation->id, $user->id);

        $messages = $conversation->messages()
            ->with(['sender', 'attachedProduct.images', 'attachedProduct.gameCategory.game', 'attachedOrder'])
            ->get()
            ->map(fn (ConversationMessage $msg): array => $this->serializeMessage($msg, $user->id))
            ->values();

        return response()->json([
            'ok' => true,
            'conversation_id' => $conversation->id,
            'messages' => $messages,
            'unread_total' => $this->conversationService->getTotalUnreadCount($user->id),
        ]);
    }

    public function sendMessage(Request $request, Conversation $conversation): JsonResponse|RedirectResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:1500'],
            'attached_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'attached_order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);

        try {
            $msg = $this->conversationService->sendMessage(
                conversationId: $conversation->id,
                senderId: $request->user()->id,
                rawMessage: $validated['message'],
                attachedProductId: isset($validated['attached_product_id']) ? (int) $validated['attached_product_id'] : null,
                attachedOrderId: isset($validated['attached_order_id']) ? (int) $validated['attached_order_id'] : null,
            );

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => true,
                    'message' => $this->serializeMessage($msg, $request->user()->id),
                    'warning' => $msg->is_flagged_bypass
                        ? "Peringatan Anti-Bypass: Pola [{$msg->detected_violation_type}] otomatis disensor demi keamanan transaksi."
                        : null,
                ]);
            }

            if ($msg->is_flagged_bypass) {
                return redirect()->route('inbox.index', ['conversation' => $conversation->id])
                    ->with('warning', "Peringatan Anti-Bypass: Pola [{$msg->detected_violation_type}] otomatis disensor.");
            }

            return redirect()->route('inbox.index', ['conversation' => $conversation->id]);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        } catch (DomainException $e) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeMessage(ConversationMessage $msg, int $viewerId): array
    {
        $attachedProduct = null;
        if ($msg->attachedProduct) {
            $attachedProduct = [
                'id' => $msg->attachedProduct->id,
                'title' => $msg->attachedProduct->title,
                'slug' => $msg->attachedProduct->slug,
                'price' => (float) $msg->attachedProduct->price,
                'price_formatted' => 'Rp '.number_format((float) $msg->attachedProduct->price, 0, ',', '.'),
                'thumbnail_url' => $msg->attachedProduct->primaryThumbnailUrl(),
                'url' => $msg->attachedProduct->deleted_at === null ? route('marketplace.show', $msg->attachedProduct->slug) : null,
            ];
        }

        return [
            'id' => $msg->id,
            'sender_id' => (int) $msg->sender_id,
            'sender_name' => $msg->sender?->name ?? 'Pengguna',
            'sender_role' => $msg->sender_role,
            'is_mine' => (int) $msg->sender_id === $viewerId,
            'sanitized_message' => $msg->sanitized_message,
            'is_flagged_bypass' => (bool) $msg->is_flagged_bypass,
            'detected_violation_type' => $msg->detected_violation_type,
            'attached_product' => $attachedProduct,
            'read' => $msg->read_at !== null,
            'time_label' => $msg->created_at?->format('H:i') ?? '',
            'date_label' => $msg->created_at?->format('d M Y') ?? '',
        ];
    }
}
