<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Daily weighted-average price per trading hub — written only by PriceIndexService. */
class HubPriceIndex extends Model
{
    protected $table = 'hub_price_index';

    public const SIGNALS = ['stable', 'rising', 'spike', 'falling', 'drop'];

    protected $fillable = [
        'trading_hub_id', 'crop_variety_id', 'price_date', 'weighted_avg_price', 'feed_avg_price', 'contract_vwap',
        'min_price', 'max_price', 'feed_volume_kg', 'contract_volume_kg', 'feed_count', 'contract_count',
        'change_1d_pct', 'change_7d_pct', 'signal', 'computed_at',
    ];

    protected function casts(): array
    {
        return [
            'price_date' => 'date',
            'weighted_avg_price' => 'float',
            'feed_avg_price' => 'float',
            'contract_vwap' => 'float',
            'min_price' => 'float',
            'max_price' => 'float',
            'feed_volume_kg' => 'float',
            'contract_volume_kg' => 'float',
            'feed_count' => 'integer',
            'contract_count' => 'integer',
            'change_1d_pct' => 'float',
            'change_7d_pct' => 'float',
            'computed_at' => 'datetime',
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
