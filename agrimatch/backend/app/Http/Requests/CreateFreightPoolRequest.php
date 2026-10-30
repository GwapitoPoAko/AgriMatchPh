<?php

namespace App\Http\Requests;

use App\Models\FreightPool;
use App\Models\HarvestListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CreateFreightPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', FreightPool::class);
    }

    public function rules(): array
    {
        return [
            'trading_hub_id' => ['required', 'integer', 'exists:trading_hubs,id'],
            'departure_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today', 'before_or_equal:' . now()->addDays(90)->toDateString()],
            'loads' => ['required', 'array', 'min:1', 'max:30'],
            'loads.*.harvest_listing_id' => ['required', 'integer', 'distinct', 'exists:harvest_listings,id'],
            'loads.*.volume_kg' => ['required', 'numeric', 'min:50', 'max:200000'],
            'solo' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $user = $this->user();
                $listings = HarvestListing::whereIn('id', collect($this->input('loads'))->pluck('harvest_listing_id'))->get()->keyBy('id');
                foreach ($this->input('loads') as $i => $load) {
                    $listing = $listings[$load['harvest_listing_id']] ?? null;
                    $mine = $listing && ($listing->seller_id === $user->id
                        || ($listing->cooperative_id && $user->managesCooperative($listing->cooperative_id)));
                    if (! $mine) {
                        $validator->errors()->add("loads.{$i}.harvest_listing_id", 'You can only add loads from your own (or your cooperative\'s) listings.');
                    }
                    if ($listing && (float) $load['volume_kg'] > $listing->stock_volume_kg) {
                        $validator->errors()->add("loads.{$i}.volume_kg", 'Load exceeds the listing\'s stock.');
                    }
                }
                if ($listings->pluck('municipality')->unique()->count() > 1) {
                    $validator->errors()->add('loads', 'All loads in one truck pool must start from the same municipality.');
                }
            },
        ];
    }
}
