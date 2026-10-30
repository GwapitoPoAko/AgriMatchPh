<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropVariety extends Model
{
    protected $fillable = [
        'crop_category_id', 'name', 'slug', 'local_name', 'scientific_name', 'unit',
        'shelf_life_days', 'typical_season', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'shelf_life_days' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(CropCategory::class, 'crop_category_id');
    }

    public function harvestListings(): HasMany
    {
        return $this->hasMany(HarvestListing::class);
    }

    public function priceIndex(): HasMany
    {
        return $this->hasMany(RegionalPriceIndex::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
