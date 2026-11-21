<?php

namespace App\Providers;

use App\Models\CartItem;
use App\Models\ModerationReport;
use App\Models\Order;
use App\Models\SellerKycVerification;
use App\Models\User;
use App\Models\WarrantyClaim;
use App\Models\WithdrawalRequest;
use App\Services\ConversationService;
use App\Services\NotificationService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->configureRateLimiters();

        View::composer('layouts.app', function ($view): void {
            if (! Schema::hasTable('users')) {
                return;
            }

            $demoSwitcherEnabled = (bool) config('nexatrade.demo_switcher_enabled', false);

            // Auto-login Buyer 1 ONLY when Demo Switcher is enabled, outside unit tests, and user has not explicitly logged out.
            $authUser = Auth::user();
            if (
                ! $authUser
                && $demoSwitcherEnabled
                && ! app()->runningUnitTests()
                && ! session('nexatrade_logged_out', false)
            ) {
                $defaultBuyer = User::where('email', 'bagas@nexatrade.id')->first();
                if ($defaultBuyer) {
                    Auth::login($defaultBuyer);
                    $authUser = $defaultBuyer;
                }
            }

            $authUser?->load(['wallet', 'sellerProfile']);

            $demoUsers = $demoSwitcherEnabled
                ? User::with(['wallet', 'sellerProfile'])
                    ->whereIn('email', [
                        'bagas@nexatrade.id',
                        'proplayer@nexatrade.id',
                        'juragan@nexatrade.id',
                        'sultan@nexatrade.id',
                        'admin@nexatrade.id',
                    ])
                    ->orderByRaw("FIELD(email, 'bagas@nexatrade.id', 'proplayer@nexatrade.id', 'juragan@nexatrade.id', 'sultan@nexatrade.id', 'admin@nexatrade.id')")
                    ->get()
                : collect();

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
                'demoSwitcherEnabled' => $demoSwitcherEnabled,
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
                'openWarrantyClaims' => $data['openWarrantyClaims'] ?? (Schema::hasTable('warranty_claims') ? WarrantyClaim::whereIn('status', ['open', 'investigating'])->count() : 0),
                'openReports' => $data['openReports'] ?? (Schema::hasTable('moderation_reports') ? ModerationReport::where('status', 'open')->count() : 0),
            ]);
        });
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('auth-login', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email', 'guest')));

            return Limit::perMinute(10)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('voucher-preview', function (Request $request): Limit {
            $key = $request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute(20)->by($key);
        });

        RateLimiter::for('nickname-verify', function (Request $request): Limit {
            $key = $request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute(30)->by($key);
        });

        RateLimiter::for('wallet-sensitive', function (Request $request): Limit {
            $key = $request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute(10)->by($key);
        });

        RateLimiter::for('chat-send', function (Request $request): Limit {
            $key = $request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinute(40)->by($key);
        });

        RateLimiter::for('auth-recovery', function (Request $request): Limit {
            $email = strtolower(trim((string) $request->input('email', 'guest')));

            return Limit::perMinutes(15, 5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('pin-recovery', function (Request $request): Limit {
            $key = $request->user()?->id ? 'u:'.$request->user()->id : 'ip:'.$request->ip();

            return Limit::perMinutes(15, 3)->by($key);
        });
    }
}
