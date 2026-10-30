<?php

namespace App\Http\Requests;

use App\Models\HarvestListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class JoinFreightPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('join', $this->route('freightPool'));
    }

    public function rules(): array
    {
        return [
            'harvest_listing_id' => ['required', 'integer', 'exists:harvest_listings,id'],
            'volume_kg' => ['required', 'numeric', 'min:50', 'max:200000'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }
                $listing = HarvestListing::find($this->integer('harvest_listing_id'));
                $user = $this->user();
                $mine = $listing->seller_id === $user->id
                    || ($listing->cooperative_id && $user->managesCooperative($listing->cooperative_id));
                if (! $mine) {
                    $validator->errors()->add('harvest_listing_id', 'You can only add your own listings to a truck.');
                }
                if ((float) $this->input('volume_kg') > $listing->stock_volume_kg) {
                    $validator->errors()->add('volume_kg', 'Load exceeds the listing\'s stock.');
                }
                $pool = $this->route('freightPool');
                if ($listing->trading_hub_id && $listing->trading_hub_id !== $pool->trading_hub_id) {
                    $validator->errors()->add('harvest_listing_id', 'This lot is headed to a different trading hub.');
                }
            },
        ];
    }
}
