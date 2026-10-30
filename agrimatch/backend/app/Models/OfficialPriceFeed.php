<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Raw DA Bantay Presyo / AMAS / PSA report for one hub, crop and day. */
class OfficialPriceFeed extends Model
{
    public const SOURCES = ['da_bantay_presyo', 'da_amas', 'psa_farmgate', 'lgu_market_survey'];

    protected $fillable = [
        'trading_hub_id', 'crop_variety_id', 'report_date', 'source', 'price_low', 'price_high', 'price_avg',
        'reported_volume_kg', 'reference_code', 'ingested_at',
    ];

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'price_low' => 'float',
            'price_high' => 'float',
            'price_avg' => 'float',
            'reported_volume_kg' => 'float',
            'ingested_at' => 'datetime',
        ];
    }

    public function hub(): BelongsTo
    {
        return $this->belongsTo(TradingHub::class, 'trading_hub_id');
    }

    public function variety(): BelongsTo
    {
        return $this->belongsTo(CropVariety::class, 'crop_variety_id');
    }
}
