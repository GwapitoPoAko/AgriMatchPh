<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FreightPoolMember extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_PICKED_UP = 'picked_up';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_WITHDRAWN = 'withdrawn';

    protected $fillable = [
        'freight_pool_id', 'user_id', 'harvest_listing_id', 'volume_kg', 'pickup_barangay',
        'pickup_latitude', 'pickup_longitude',
    ];

    protected function casts(): array
    {
        return [
            'volume_kg' => 'float',
            'pickup_latitude' => 'float',
            'pickup_longitude' => 'float',
            'distance_to_hub_km' => 'float',
            'cost_share' => 'float',
            'solo_cost' => 'float',
            'savings' => 'float',
        ];
    }

    public function pool(): BelongsTo
    {
        return $this->belongsTo(FreightPool::class, 'freight_pool_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(HarvestListing::class, 'harvest_listing_id');
    }
}
