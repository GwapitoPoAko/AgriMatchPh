<?php

namespace App\Http\Requests;

use App\Models\Cooperative;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCoopPoolRequest extends FormRequest
{
    public function authorize(): bool
    {
        $coop = Cooperative::find($this->integer('cooperative_id'));

        return $coop && (bool) $this->user()?->can('managePools', $coop);
    }

    public function rules(): array
    {
        return [
            'cooperative_id' => ['required', 'integer', 'exists:cooperatives,id'],
            'crop_variety_id' => ['required', 'integer', Rule::exists('crop_varieties', 'id')->where('is_active', true)],
            'contributions' => ['required', 'array', 'min:' . config('agrimatch.coop_pool.min_members'), 'max:' . config('agrimatch.coop_pool.max_members')],
            'contributions.*.harvest_listing_id' => ['required', 'integer', 'distinct', 'exists:harvest_listings,id'],
            'contributions.*.volume_kg' => ['required', 'numeric', 'min:1', 'max:10000000'],
            'unit_price' => ['nullable', 'numeric', 'min:0.5', 'max:100000', 'decimal:0,2'],
            'minimum_order_kg' => ['nullable', 'numeric', 'min:0'],
            'title' => ['nullable', 'string', 'min:8', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['title', 'description'] as $key) {
            if (is_string($this->input($key))) {
                $this->merge([$key => trim(strip_tags($this->input($key)))]);
            }
        }
    }
}
