<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** @var list<string> */
    protected $fillable = [
        'name',
        'email',
        'password',
        'wallet_pin_hash',
        'wallet_pin_attempts',
        'wallet_pin_locked_until',
        'wallet_pin_set_at',
        'role',
        'is_active',
    ];

    /** @var list<string> */
    protected $hidden = [
        'password',
        'wallet_pin_hash',
        'remember_token',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'wallet_pin_attempts' => 'integer',
            'wallet_pin_locked_until' => 'datetime',
            'wallet_pin_set_at' => 'datetime',
            'is_active' => 'boolean',
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

    public function sellerProfile(): HasOne
    {
        return $this->hasOne(SellerProfile::class, 'user_id', 'id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class, 'user_id', 'id');
    }

    public function walletMutations(): HasMany
    {
        return $this->hasMany(WalletMutation::class, 'user_id', 'id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'seller_id', 'id');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class, 'user_id', 'id');
    }

    public function buyerInvoices(): HasMany
    {
        return $this->hasMany(PaymentInvoice::class, 'buyer_id', 'id');
    }

    public function buyerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'buyer_id', 'id');
    }

    public function sellerOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'seller_id', 'id');
    }

    public function withdrawalRequests(): HasMany
    {
        return $this->hasMany(WithdrawalRequest::class, 'user_id', 'id');
    }

    public function bankAccounts(): HasMany
    {
        return $this->hasMany(UserBankAccount::class, 'user_id', 'id');
    }

    public function kycVerifications(): HasMany
    {
        return $this->hasMany(SellerKycVerification::class, 'user_id', 'id');
    }

    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'user_id', 'id');
    }

    public function buyerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'buyer_id', 'id');
    }

    public function sellerConversations(): HasMany
    {
        return $this->hasMany(Conversation::class, 'seller_id', 'id');
    }

    public function wishlistedProducts(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'wishlists', 'user_id', 'product_id')
            ->withPivot('created_at')
            ->orderByPivot('created_at', 'desc');
    }

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

    public function isSeller(): bool
    {
        return $this->role === 'seller';
    }

    public function isBuyer(): bool
    {
        return $this->role === 'buyer';
    }
}
