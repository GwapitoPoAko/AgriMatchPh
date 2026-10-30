<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class FreightPool extends Model
{
    public const STATUS_FORMING = 'forming';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_IN_TRANSIT = 'in_transit';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';

    /** Pools whose cost is still live and must track fuel price changes. */
    public const OPEN_STATUSES = [self::STATUS_FORMING, self::STATUS_CONFIRMED];

    /** Allowed forward transitions for PATCH /freight-pools/{id}/status. */
    public const TRANSITIONS = [
        self::STATUS_FORMING => [self::STATUS_CONFIRMED, self::STATUS_CANCELLED],
        self::STATUS_CONFIRMED => [self::STATUS_IN_TRANSIT, self::STATUS_CANCELLED],
        self::STATUS_IN_TRANSIT => [self::STATUS_DELIVERED],
        self::STATUS_DELIVERED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = ['trading_hub_id', 'origin_province_id', 'origin_municipality', 'departure_date', 'is_solo'];

    protected function casts(): array
    {
        return [
            'capacity_kg' => 'float',
            'total_volume_kg' => 'float',
            'route_distance_km' => 'float',
            'base_cost' => 'float',
            'fuel_surcharge_pct' => 'float',
            'fuel_surcharge_amount' => 'float',
            'total_cost' => 'float',
            'truck_count' => 'integer',
            'departure_date' => 'date',
            'route_json' => 'array',
            'last_recalculated_at' => 'datetime',
            'is_solo' => 'boolean',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(TradingHub::class, 'trading_hub_id');
    }

    public function originProvince(): BelongsTo
    {
        return $this->belongsTo(Province::class, 'origin_province_id');
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'driver_id');
    }

    public function fuelPriceLog(): BelongsTo
    {
        return $this->belongsTo(FuelPriceLog::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(FreightPoolMember::class);
    }

    public function activeMembers(): HasMany
    {
        return $this->hasMany(FreightPoolMember::class)->where('status', '!=', FreightPoolMember::STATUS_WITHDRAWN);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public static function nextReferenceCode(): string
    {
        $year = Carbon::now()->year;
        $last = static::where('reference_code', 'like', "FP-{$year}-%")->orderByDesc('reference_code')->value('reference_code');

        return sprintf('FP-%d-%05d', $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }
}
