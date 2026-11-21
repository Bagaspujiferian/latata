<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Models\Order;
use App\Models\SellerKycVerification;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\ConversationService;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        View::composer('layouts.app', function ($view): void {
            if (! Schema::hasTable('users')) {
                return;
            }

            // Auto-login Buyer 1 seamlessly on first demo visit if guest, so 1-click checkout works immediately.
            $authUser = Auth::user();
            if (! $authUser && ! app()->runningUnitTests()) {
                $defaultBuyer = User::where('email', 'bagas@nexatrade.id')->first();
                if ($defaultBuyer) {
                    Auth::login($defaultBuyer);
                    $authUser = $defaultBuyer;
                }
            }

            $authUser?->load(['wallet', 'sellerProfile']);

            $demoUsers = User::with(['wallet', 'sellerProfile'])
                ->whereIn('email', [
                    'bagas@nexatrade.id',
                    'proplayer@nexatrade.id',
                    'juragan@nexatrade.id',
                    'sultan@nexatrade.id',
                    'admin@nexatrade.id',
                ])
                ->orderByRaw("FIELD(email, 'bagas@nexatrade.id', 'proplayer@nexatrade.id', 'juragan@nexatrade.id', 'sultan@nexatrade.id', 'admin@nexatrade.id')")
                ->get();

            $cartCount = $authUser
                ? (int) CartItem::where('user_id', $authUser->id)->sum('quantity')
                : 0;

            $wishlistCount = ($authUser && Schema::hasTable('wishlists'))
                ? (int) DB::table('wishlists')->where('user_id', $authUser->id)->count()
                : 0;

            $inboxUnreadCount = ($authUser && Schema::hasTable('conversations'))
                ? app(ConversationService::class)->getTotalUnreadCount($authUser->id)
                : 0;

            $notificationBell = ($authUser && Schema::hasTable('user_notifications'))
                ? app(NotificationService::class)->getBellSummary($authUser->id, 8)
                : ['unread_count' => 0, 'items' => collect()];

            $openDisputeCount = Order::where('status', 'DISPUTED')->count();
            $pendingKycCount = Schema::hasTable('seller_kyc_verifications')
                ? SellerKycVerification::where('status', 'pending')->count()
                : 0;

            $view->with([
                'currentUser' => $authUser,
                'demoUsers' => $demoUsers,
                'cartCount' => $cartCount,
                'wishlistCount' => $wishlistCount,
                'inboxUnreadCount' => $inboxUnreadCount,
                'notificationBell' => $notificationBell,
                'openDisputeCount' => $openDisputeCount,
                'pendingKycCount' => $pendingKycCount,
            ]);
        });

        View::composer('components.admin-sidebar', function ($view): void {
            if (! Schema::hasTable('orders')) {
                return;
            }

            $data = $view->getData();
            $view->with([
                'openDisputes' => $data['openDisputes'] ?? Order::where('status', 'DISPUTED')->count(),
                'pendingWithdrawals' => $data['pendingWithdrawals'] ?? WithdrawalRequest::where('status', 'PENDING')->count(),
                'pendingKycs' => $data['pendingKycs'] ?? (Schema::hasTable('seller_kyc_verifications') ? SellerKycVerification::where('status', 'pending')->count() : 0),
            ]);
        });
    }
}
