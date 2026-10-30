<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BuyerDemand extends Model
{
    public const OPEN_STATUSES = ['open', 'partially_matched'];

    protected $fillable = [
        'reference_code', 'buyer_id', 'crop_variety_id', 'delivery_province_id', 'quantity_kg',
        'max_unit_price', 'needed_from', 'needed_by', 'max_distance_km', 'min_quality_grade', 'notes', 'status',
    ];

    protected function casts(): array
    {
        return [
            'quantity_kg' => 'float',
            'max_unit_price' => 'float',
            'max_distance_km' => 'integer',
            'needed_from' => 'date',
            'needed_by' => 'date',
        ];
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class, 'crop_variety_id');
    }

    public function deliveryProvince(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'delivery_province_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(B2bOffer::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }
}
