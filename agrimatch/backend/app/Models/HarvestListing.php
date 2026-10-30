<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class HarvestListing extends Model
{
    use SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_UPCOMING = 'upcoming';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_RESERVED = 'reserved';
    public const STATUS_POOLED = 'pooled';
    public const STATUS_SOLD_OUT = 'sold_out';
    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_DRAFT, self::STATUS_UPCOMING, self::STATUS_ACTIVE, self::STATUS_RESERVED,
        self::STATUS_POOLED, self::STATUS_SOLD_OUT, self::STATUS_EXPIRED,
    ];

    /** Statuses a buyer can still transact against. */
    public const TRADABLE_STATUSES = [self::STATUS_ACTIVE, self::STATUS_UPCOMING];

    /** Grade rank: lower is better. */
    public const GRADE_RANK = ['A' => 1, 'B' => 2, 'C' => 3];

    /** Certified grade rank (inspection outcome): lower is better. */
    public const CERTIFIED_RANK = ['export' => 1, 'grade_a' => 2, 'class_b' => 3, 'class_c' => 4, 'rejected' => 9];

    /**
     * Seller-editable attributes only. Ownership (seller_id), verification,
     * pooling links, certification and counters are set by services, never
     * mass-assigned from request input.
     */
    protected $fillable = [
        'crop_variety_id', 'province_id', 'municipality', 'barangay', 'title', 'description', 'quality_grade',
        'is_organic', 'stock_volume_kg', 'available_volume_kg', 'minimum_order_kg', 'unit_price',
        'price_negotiable', 'harvest_date_start', 'harvest_date_end', 'status', 'image_url',
        'pickup_latitude', 'pickup_longitude',
        'latitude', 'longitude',
    ];

    protected function casts(): array
    {
        return [
            'is_organic' => 'boolean',
            'price_negotiable' => 'boolean',
            'is_verified' => 'boolean',
            'is_pool_parent' => 'boolean',
            'stock_volume_kg' => 'float',
            'available_volume_kg' => 'float',
            'minimum_order_kg' => 'float',
            'pooled_volume_kg' => 'float',
            'unit_price' => 'float',
            'pickup_latitude' => 'float',
            'pickup_longitude' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
            'views_count' => 'integer',
            'harvest_date_start' => 'date',
            'harvest_date_end' => 'date',
        ];
    }

    /* ---------------------------------------------------------------- */
    /* Relationships                                                     */
    /* ---------------------------------------------------------------- */

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class, 'crop_variety_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function tradingHub(): BelongsTo
    {
        return $this->belongsTo(TradingHub::class);
    }

    /** Coop pool this member lot was aggregated into. */
    public function parentListing(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_listing_id');
    }

    /** Member lots aggregated into this coop pool (when is_pool_parent). */
    public function poolMembers(): HasMany
    {
        return $this->hasMany(self::class, 'parent_listing_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(B2bOffer::class);
    }

    public function pendingOffers(): HasMany
    {
        return $this->hasMany(B2bOffer::class)->where('status', B2bOffer::STATUS_PENDING);
    }

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }

    public function inspections(): HasMany
    {
        return $this->hasMany(QualityInspection::class);
    }

    public function latestInspection(): HasOne
    {
        return $this->hasOne(QualityInspection::class)->latestOfMany();
    }

    /* ---------------------------------------------------------------- */
    /* Query scopes                                                      */
    /* ---------------------------------------------------------------- */

    public function scopeTradable(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), self::TRADABLE_STATUSES)
            ->where($this->qualifyColumn('available_volume_kg'), '>', 0);
    }

    /** Listings whose harvest window overlaps [from, to]. Either bound may be null. */
    public function scopeHarvestOverlaps(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn (Builder $q) => $q->whereDate($this->qualifyColumn('harvest_date_end'), '>=', $from))
            ->when($to, fn (Builder $q) => $q->whereDate($this->qualifyColumn('harvest_date_start'), '<=', $to));
    }

    public function scopeMeetsGrade(Builder $query, string $minimumGrade): Builder
    {
        $rank = self::GRADE_RANK[$minimumGrade] ?? 3;
        $allowed = array_keys(array_filter(self::GRADE_RANK, fn (int $r) => $r <= $rank));

        return $query->whereIn($this->qualifyColumn('quality_grade'), $allowed);
    }

    /* ---------------------------------------------------------------- */
    /* Domain helpers                                                    */
    /* ---------------------------------------------------------------- */

    public static function statusForHarvestStart(CarbonInterface|string $start): string
    {
        $start = $start instanceof CarbonInterface ? $start : Carbon::parse($start);

        return $start->isAfter(Carbon::today()) ? self::STATUS_UPCOMING : self::STATUS_ACTIVE;
    }

    public static function nextReferenceCode(): string
    {
        $year = Carbon::now()->year;
        $last = static::withTrashed()
            ->where('reference_code', 'like', "HL-{$year}-%")
            ->orderByDesc('reference_code')
            ->value('reference_code');

        $sequence = $last ? ((int) substr($last, -5)) + 1 : 1;

        return sprintf('HL-%d-%05d', $year, $sequence);
    }

    public function isTradable(): bool
    {
        return in_array($this->status, self::TRADABLE_STATUSES, true) && $this->available_volume_kg > 0;
    }

    public function isPooledMember(): bool
    {
        return $this->parent_listing_id !== null;
    }

    public function certifiedGradeLabel(): ?string
    {
        return $this->certified_grade ? config("agrimatch.inspection.grades.{$this->certified_grade}") : null;
    }
}
