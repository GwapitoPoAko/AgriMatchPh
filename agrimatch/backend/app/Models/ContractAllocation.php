<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A member farmer's share of a cooperative pool contract. */
class ContractAllocation extends Model
{
    protected $fillable = [
        'contract_id', 'harvest_listing_id', 'farmer_id', 'allocated_volume_kg', 'unit_price',
        'gross_amount', 'coop_fee_pct', 'net_payout', 'status',
    ];

    protected function casts(): array
    {
        return [
            'allocated_volume_kg' => 'float',
            'unit_price' => 'float',
            'gross_amount' => 'float',
            'coop_fee_pct' => 'float',
            'net_payout' => 'float',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(HarvestListing::class, 'harvest_listing_id');
    }

    public function farmer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'farmer_id');
    }
}
