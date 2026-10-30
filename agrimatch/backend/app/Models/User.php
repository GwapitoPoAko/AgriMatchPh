<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;

    public const ROLE_FARMER = 'farmer';
    public const ROLE_COOP_MANAGER = 'coop_manager';
    public const ROLE_INSPECTOR = 'quality_inspector';
    public const ROLE_TRADER = 'trader';
    public const ROLE_BUYER = 'buyer';
    public const ROLE_DRIVER = 'driver';
    public const ROLE_ADMIN = 'admin';

    public const ROLES = [
        self::ROLE_FARMER, self::ROLE_COOP_MANAGER, self::ROLE_INSPECTOR, self::ROLE_TRADER,
        self::ROLE_BUYER, self::ROLE_DRIVER, self::ROLE_ADMIN,
    ];

    /** Roles allowed to publish harvest listings (supply side). */
    public const SELLER_ROLES = [self::ROLE_FARMER, self::ROLE_COOP_MANAGER, self::ROLE_TRADER];

    /** Roles allowed to post demand and make offers (demand side). */
    public const BUYER_ROLES = [self::ROLE_BUYER, self::ROLE_TRADER];

    /** Roles that ship produce and can organise / join freight pools. */
    public const SHIPPER_ROLES = [self::ROLE_FARMER, self::ROLE_COOP_MANAGER, self::ROLE_TRADER];

    /**
     * Explicit allow-list. `role`, `is_verified` and `status` are deliberately
     * NOT mass-assignable — they can only change through forceFill() in admin code.
     */
    protected $fillable = ['name', 'email', 'phone', 'password'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_verified' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /* ---------------------------------------------------------------- */

    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    public function harvestListings(): HasMany
    {
        return $this->hasMany(HarvestListing::class, 'seller_id');
    }

    public function buyerDemands(): HasMany
    {
        return $this->hasMany(BuyerDemand::class, 'buyer_id');
    }

    public function offersMade(): HasMany
    {
        return $this->hasMany(B2bOffer::class, 'buyer_id');
    }

    public function offersReceived(): HasMany
    {
        return $this->hasMany(B2bOffer::class, 'seller_id');
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(AppNotification::class);
    }

    public function managedCooperatives(): HasMany
    {
        return $this->hasMany(Cooperative::class, 'manager_id');
    }

    /* ---------------------------------------------------------------- */

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function canSell(): bool
    {
        return $this->isActive() && (in_array($this->role, self::SELLER_ROLES, true) || $this->isAdmin());
    }

    public function canBuy(): bool
    {
        return $this->isActive() && (in_array($this->role, self::BUYER_ROLES, true) || $this->isAdmin());
    }

    public function canShip(): bool
    {
        return $this->isActive() && (in_array($this->role, self::SHIPPER_ROLES, true) || $this->isAdmin());
    }

    /** Cooperative this user belongs to (member) or manages. */
    public function cooperativeId(): ?int
    {
        return $this->profile?->cooperative_id
            ?? $this->managedCooperatives()->value('id');
    }

    public function managesCooperative(int $cooperativeId): bool
    {
        return $this->role === self::ROLE_COOP_MANAGER
            && Cooperative::whereKey($cooperativeId)->where('manager_id', $this->id)->exists();
    }

    public function scopeRole(Builder $query, string $role): Builder
    {
        return $query->where('role', $role);
    }
}
