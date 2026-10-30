<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Cooperative extends Model
{
    protected $fillable = [
        'name', 'cda_registration_no', 'province_id', 'municipality', 'barangay', 'member_count',
        'founded_year', 'primary_crops', 'coop_fee_pct', 'is_cda_accredited',
    ];

    protected function casts(): array
    {
        return [
            'member_count' => 'integer',
            'founded_year' => 'integer',
            'coop_fee_pct' => 'float',
            'is_cda_accredited' => 'boolean',
        ];
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function memberProfiles(): HasMany
    {
        return $this->hasMany(UserProfile::class);
    }

    public function members(): HasManyThrough
    {
        return $this->hasManyThrough(User::class, UserProfile::class, 'cooperative_id', 'id', 'id', 'user_id');
    }

    public function listings(): HasMany
    {
        return $this->hasMany(HarvestListing::class);
    }

    public function poolListings(): HasMany
    {
        return $this->hasMany(HarvestListing::class)->where('is_pool_parent', true);
    }
}
