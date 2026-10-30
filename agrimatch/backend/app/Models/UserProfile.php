<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    protected $fillable = [
        'business_name', 'business_type', 'bio', 'province_id', 'municipality', 'barangay',
        'latitude', 'longitude',
        'farm_size_hectares', 'monthly_capacity_kg', 'avatar_url', 'sms_opt_in', 'preferred_language',
    ];

    protected function casts(): array
    {
        return [
            'farm_size_hectares' => 'decimal:2',
            'monthly_capacity_kg' => 'decimal:2',
            'rating' => 'float',
            'total_transactions' => 'integer',
            'sms_opt_in' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'verified_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }
}
