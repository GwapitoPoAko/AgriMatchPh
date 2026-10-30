<?php

namespace App\Models;

use App\Support\Geo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class TradingHub extends Model
{
    protected $fillable = [
        'code', 'name', 'short_name', 'province_id', 'municipality', 'barangay', 'latitude', 'longitude',
        'hub_type', 'operating_hours', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function priceIndex(): HasMany
    {
        return $this->hasMany(HubPriceIndex::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function regionCode(): string
    {
        return $this->province?->region_code ?? config('agrimatch.fuel.fallback_region');
    }

    /** Nearest active hub to a coordinate (hub list is tiny and cached). */
    public static function nearestTo(float $lat, float $lng): ?self
    {
        $hubs = Cache::remember('trading-hubs:active', 3600, fn () => static::active()->with('province:id,region_code')->get());

        return $hubs->sortBy(fn (self $h) => Geo::haversineKm($lat, $lng, $h->latitude, $h->longitude))->first();
    }
}
