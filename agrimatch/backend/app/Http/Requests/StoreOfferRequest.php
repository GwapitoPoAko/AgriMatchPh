<?php

namespace App\Http\Requests;

use App\Models\B2bOffer;
use App\Models\HarvestListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', B2bOffer::class);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('message'))) {
            $this->merge(['message' => trim(strip_tags($this->input('message')))]);
        }
    }

    public function rules(): array
    {
        return [
            'harvest_listing_id' => ['required', 'integer', 'exists:harvest_listings,id'],
            'buyer_demand_id' => ['nullable', 'integer', Rule::exists('buyer_demands', 'id')->where('buyer_id', $this->user()?->id)],
            'volume_kg' => ['required', 'numeric', 'min:1'],
            'unit_price' => ['required', 'numeric', 'min:0.5', 'max:100000'],
            'delivery_terms' => ['required', Rule::in(['farm_gate', 'delivered', 'pickup_hub'])],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:today'],
            'payment_terms' => ['required', Rule::in(['cod', 'net_15', 'net_30', 'lc_at_sight'])],
            'message' => ['nullable', 'string', 'max:2000'],
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
                if (! $listing) {
                    return;
                }
                if ($listing->seller_id === $this->user()->id) {
                    $validator->errors()->add('harvest_listing_id', 'You cannot make an offer on your own listing.');
                }
                if (! $listing->isTradable()) {
                    $validator->errors()->add('harvest_listing_id', 'This listing is no longer accepting offers.');
                }
                $volume = (float) $this->input('volume_kg');
                if ($volume > $listing->available_volume_kg) {
                    $validator->errors()->add('volume_kg', sprintf('Only %s kg is available.', number_format($listing->available_volume_kg)));
                }
                if ($volume < $listing->minimum_order_kg) {
                    $validator->errors()->add('volume_kg', sprintf('Minimum order is %s kg.', number_format($listing->minimum_order_kg)));
                }
            },
        ];
    }
}
