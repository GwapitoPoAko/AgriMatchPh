<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CropCategory extends Model
{
    protected $fillable = ['name', 'slug', 'description', 'icon', 'color_hex', 'sort_order'];

    public function varieties(): HasMany
    {
        return $this->hasMany(CropVariety::class);
    }
}
