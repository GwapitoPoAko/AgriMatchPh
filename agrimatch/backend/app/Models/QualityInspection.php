<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class QualityInspection extends Model
{
    public const STATUS_REQUESTED = 'requested';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_IN_PROGRESS = 'in_progress';
    public const STATUS_PASSED = 'passed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const OPEN_STATUSES = [self::STATUS_REQUESTED, self::STATUS_SCHEDULED, self::STATUS_IN_PROGRESS];

    /** Only workflow-neutral fields; result fields are written via forceFill() by the inspector flow. */
    protected $fillable = ['harvest_listing_id', 'scheduled_at', 'remarks', 'image_url'];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'inspected_at' => 'datetime',
            'sample_size_kg' => 'float',
            'moisture_pct' => 'float',
            'defect_pct' => 'float',
            'foreign_matter_pct' => 'float',
            'brix' => 'float',
            'pesticide_residue_passed' => 'boolean',
            'valid_until' => 'date',
        ];
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(HarvestListing::class, 'harvest_listing_id');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_id');
    }

    public function cooperative(): BelongsTo
    {
        return $this->belongsTo(Cooperative::class);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public static function nextReferenceCode(): string
    {
        $year = Carbon::now()->year;
        $last = static::where('reference_code', 'like', "QI-{$year}-%")->orderByDesc('reference_code')->value('reference_code');

        return sprintf('QI-%d-%05d', $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }

    public static function nextCertificateNo(): string
    {
        $year = Carbon::now()->year;
        $last = static::where('certificate_no', 'like', "AMQ-{$year}-%")->orderByDesc('certificate_no')->value('certificate_no');

        return sprintf('AMQ-%d-%05d', $year, $last ? ((int) substr($last, -5)) + 1 : 1);
    }
}
