<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegionalPriceIndex extends Model
{
    protected $table = 'regional_price_index';

    /** Price columns a client may chart. */
    public const METRICS = ['farmgate' => 'farmgate_price', 'wholesale' => 'wholesale_price', 'retail' => 'retail_price'];

    protected $fillable = [
        'crop_variety_id', 'province_id', 'price_date', 'farmgate_price', 'wholesale_price', 'retail_price',
        'min_price', 'max_price', 'avg_price', 'volume_traded_kg', 'sample_size', 'source',
    ];

    protected function casts(): array
    {
        return [
            'price_date' => 'date',
            'farmgate_price' => 'float',
            'wholesale_price' => 'float',
            'retail_price' => 'float',
            'min_price' => 'float',
            'max_price' => 'float',
            'avg_price' => 'float',
            'volume_traded_kg' => 'float',
            'sample_size' => 'integer',
        ];
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class, 'crop_variety_id');
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }
}
