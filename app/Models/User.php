<?php

namespace App\Models;

use App\Enums\AdminSubrole;
use App\Security\AdminPermissionMatrix;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;

/**
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property Carbon|null $wallet_pin_locked_until
 * @property Carbon|null $wallet_pin_set_at
 * @property Carbon|null $banned_at
 * @property AdminSubrole|null $admin_subrole
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'email_verified_at',
        'phone_encrypted',
        'phone_blind_hash',
        'phone_verified_at',
        'password',
        'wallet_pin_hash',
        'wallet_pin_attempts',
        'wallet_pin_locked_until',
        'wallet_pin_set_at',
        'role',
        'admin_subrole',
        'is_active',
        'is_banned',
        'banned_at',
        'ban_reason',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'wallet_pin_hash',
        'phone_encrypted',
        'phone_blind_hash',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_pin_attempts' => 'integer',
            'wallet_pin_locked_until' => 'datetime',
            'wallet_pin_set_at' => 'datetime',
            'admin_subrole' => AdminSubrole::class,
            'is_active' => 'boolean',
            'is_banned' => 'boolean',
            'banned_at' => 'datetime',
        ];
    }

    public function hasWalletPin(): bool
    {
        return ! empty($this->wallet_pin_hash);
    }

    public function isWalletPinLocked(): bool
    {
        return $this->wallet_pin_locked_until !== null && $this->wallet_pin_locked_until->isFuture();
    }

    /** @return HasOne<SellerProfile, $this> */
    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class, 'user_id', 'id');
    }

    /** @return HasOne<Wallet, $this> */
    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'user_id', 'id');
    }

    /** @return HasMany<WalletMutation, $this> */
    public function walletMutations(): HasMany
    {
        return $this->hasMany(WalletMutation::class, 'user_id', 'id');
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id', 'id');
    }

    /** @return HasMany<CartItem, $this> */
    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'user_id', 'id');
    }

    /** @return HasMany<PaymentInvoice, $this> */
    public function buyerInvoices(): HasMany
    {
        return $this->hasMany(PaymentInvoice::class, 'buyer_id', 'id');
    }

    /** @return HasMany<Order, $this> */
    public function buyerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id', 'id');
    }

    /** @return HasMany<Order, $this> */
    public function sellerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id', 'id');
    }

    /** @return HasMany<WithdrawalRequest, $this> */
    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class, 'user_id', 'id');
    }

    /** @return HasMany<UserBankAccount, $this> */
    public function bankAccounts(): HasMany
    {
        return $this->hasMany(UserBankAccount::class, 'user_id', 'id');
    }

    /** @return HasMany<SellerKycVerification, $this> */
    public function kycVerifications(): HasMany
    {
        return $this->hasMany(SellerKycVerification::class, 'user_id', 'id');
    }

    /** @return HasMany<UserNotification, $this> */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'user_id', 'id');
    }

    /** @return HasMany<Conversation, $this> */
    public function buyerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'buyer_id', 'id');
    }

    /** @return HasMany<Conversation, $this> */
    public function sellerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'seller_id', 'id');
    }

    /** @return BelongsToMany<Product, $this> */
    public function wishlistedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists', 'user_id', 'product_id')
            ->withPivot('created_at')
            ->orderByPivot('created_at', 'desc');
    }

    /** @return BelongsToMany<SellerProfile, $this> */
    public function followedStores(): BelongsToMany
    {
        return $this->belongsToMany(SellerProfile::class, 'store_followers', 'user_id', 'seller_id')
            ->withPivot('created_at')
            ->orderByPivot('created_at', 'desc');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function hasAdminPermission(string $permission): bool
    {
        return AdminPermissionMatrix::allows($this, $permission);
    }

    public function isSeller(): bool
    {
        return $this->role === 'seller';
    }

    public function isBuyer(): bool
    {
        return $this->role === 'buyer';
    }

    public function isFullyVerifiedForPromo(): bool
    {
        return $this->email_verified_at !== null && $this->phone_verified_at !== null;
    }

    public function decryptedPhone(): ?string
    {
        if (empty($this->phone_encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString((string) $this->phone_encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    public function maskedPhone(): ?string
    {
        $plain = $this->decryptedPhone();
        if ($plain === null || strlen($plain) < 8) {
            return null;
        }

        return substr($plain, 0, 5).'****'.substr($plain, -4);
    }

    /** @return HasMany<UserVerificationChallenge, $this> */
    public function verificationChallenges(): HasMany
    {
        return $this->hasMany(UserVerificationChallenge::class, 'user_id', 'id');
    }

    /** @return HasMany<WithdrawalApproval, $this> */
    public function withdrawalApprovals(): HasMany
    {
        return $this->hasMany(WithdrawalApproval::class, 'admin_id', 'id');
    }
}
