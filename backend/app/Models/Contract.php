<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Contract extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_IN_DELIVERY = 'in_delivery';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_DISPUTED = 'disputed';
    public const STATUS_CANCELLED = 'cancelled';

    public const LIVE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_IN_DELIVERY];

    /** Contracts that count as verified market evidence for the price index. */
    public const PRICE_EVIDENCE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_IN_DELIVERY, self::STATUS_COMPLETED];

    /**
     * Commercial terms are copied from the accepted offer by B2bOfferController.
     * Signatures, status and the coop link are written via forceFill() only.
     */
    protected $fillable = [
        'contract_number', 'b2b_offer_id', 'harvest_listing_id', 'buyer_id', 'seller_id', 'agreed_volume_kg',
        'agreed_unit_price', 'total_value', 'delivered_volume_kg', 'delivery_terms', 'payment_terms',
        'delivery_start', 'delivery_end', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'agreed_volume_kg' => 'float',
            'agreed_unit_price' => 'float',
            'total_value' => 'float',
            'delivered_volume_kg' => 'float',
            'delivery_start' => 'date',
            'delivery_end' => 'date',
            'signed_by_buyer_at' => 'datetime',
            'signed_by_seller_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(B2bOffer::class, 'b2b_offer_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(HarvestListing::class, 'harvest_listing_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(ContractAllocation::class);
    }

    public function isFullySigned(): bool
    {
        return $this->signed_by_buyer_at !== null && $this->signed_by_seller_at !== null;
    }

    public function partyRole(User $user): ?string
    {
        return match ($user->id) {
            $this->buyer_id => 'buyer',
            $this->seller_id => 'seller',
            default => null,
        };
    }

    public static function nextContractNumber(): string
    {
        $year = Carbon::now()->year;
        $last = static::where('contract_number', 'like', "AMC-{$year}-%")
            ->orderByDesc('contract_number')
            ->value('contract_number');

        $sequence = $last ? ((int) substr($last, -4)) + 1 : 1;

        return sprintf('AMC-%d-%04d', $year, $sequence);
    }
}
