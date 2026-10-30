<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Province extends Model
{
    /** Region codes grouped by major island group — used to add inter-island freight. */
    public const ISLAND_GROUPS = [
        'luzon' => ['NCR', 'CAR', 'I', 'II', 'III', 'IV-A', 'IV-B', 'V'],
        'visayas' => ['VI', 'VII', 'VIII', 'NIR'],
        'mindanao' => ['IX', 'X', 'XI', 'XII', 'XIII', 'BARMM'],
    ];

    protected $fillable = ['name', 'region', 'region_code', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function harvestListings(): HasMany
    {
        return $this->hasMany(HarvestListing::class);
    }

    public function islandGroup(): string
    {
        foreach (self::ISLAND_GROUPS as $group => $codes) {
            if (in_array($this->region_code, $codes, true)) {
                return $group;
            }
        }

        return 'unknown';
    }
}
