<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class B2bOffer extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_COUNTERED = 'countered';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_EXPIRED = 'expired';

    protected $table = 'b2b_offers';

    protected $fillable = [
        'reference_code', 'harvest_listing_id', 'buyer_demand_id', 'buyer_id', 'seller_id', 'parent_offer_id',
        'offered_by_id', 'volume_kg', 'unit_price', 'total_amount', 'delivery_terms', 'delivery_date',
        'payment_terms', 'message', 'status', 'expires_at', 'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'volume_kg' => 'float',
            'unit_price' => 'float',
            'total_amount' => 'float',
            'delivery_date' => 'date',
            'expires_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(HarvestListing::class, 'harvest_listing_id');
    }

    public function demand(): BelongsTo
    {
        return $this->belongsTo(BuyerDemand::class, 'buyer_demand_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function offeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'offered_by_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_offer_id');
    }

    public function counters(): HasMany
    {
        return $this->hasMany(self::class, 'parent_offer_id');
    }

    public function contract(): HasOne
    {
        return $this->hasOne(Contract::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_PENDING
            && ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /** The party expected to respond to this round. */
    public function counterpartyId(): int
    {
        return $this->offered_by_id === $this->buyer_id ? $this->seller_id : $this->buyer_id;
    }

    public static function nextReferenceCode(): string
    {
        $year = Carbon::now()->year;
        $last = static::where('reference_code', 'like', "OF-{$year}-%")
            ->orderByDesc('reference_code')
            ->value('reference_code');

        $sequence = $last ? ((int) substr($last, -5)) + 1 : 1;

        return sprintf('OF-%d-%05d', $year, $sequence);
    }
}
