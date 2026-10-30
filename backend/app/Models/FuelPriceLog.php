<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use App\Services\FuelPolicy;

class FuelPriceLog extends Model
{
    public const FUEL_TYPES = ['diesel', 'gasoline'];

    protected $fillable = ['region_code', 'fuel_type', 'effective_date', 'price_per_liter', 'source'];

    protected function casts(): array
    {
        return [
            'effective_date' => 'date',
            'price_per_liter' => 'float',
            'previous_price' => 'float',
            'change_amount' => 'float',
        ];
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_id');
    }

    /**
     * Latest pump price in force for a region on a date, falling back to the
     * configured region when a region has no DOE log yet.
     */
    public static function inForce(string $regionCode, string $fuelType, Carbon|string|null $on = null): ?self
    {
        $on = $on ? Carbon::parse($on)->toDateString() : Carbon::today()->toDateString();

        $query = fn (string $region) => static::query()
            ->where('region_code', $region)
            ->where('fuel_type', $fuelType)
            ->whereDate('effective_date', '<=', $on)
            ->orderByDesc('effective_date')
            ->first();

        return $query($regionCode) ?? $query(config('agrimatch.fuel.fallback_region'));
    }

    public function deltaVsBaselinePct(): float
    {
        $baseline = FuelPolicy::baseline($this->fuel_type);

        return $baseline > 0 ? round(($this->price_per_liter / $baseline - 1) * 100, 2) : 0.0;
    }
}
