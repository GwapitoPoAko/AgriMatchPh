<?php

namespace App\Http\Requests;

use App\Models\HarvestListing;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Query-string filters for GET /api/v1/harvest-listings.
 * Multi-value filters accept either repeated keys (?province_id[]=1&province_id[]=2)
 * or a comma list (?province_id=1,2).
 */
class FilterHarvestListingRequest extends FormRequest
{
    public const SORTS = ['newest', 'price_asc', 'price_desc', 'volume_desc', 'harvest_soonest', 'popular'];

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        foreach (['province_id', 'crop_variety_id', 'crop_category_id', 'status', 'quality_grade'] as $key) {
            $value = $this->query($key);
            if (is_string($value)) {
                $merge[$key] = array_values(array_filter(array_map('trim', explode(',', $value)), 'strlen'));
            }
        }
        foreach (['verified_only', 'organic_only', 'certified_only', 'coop_pools_only', 'include_pool_members'] as $key) {
            if ($this->has($key)) {
                $merge[$key] = filter_var($this->query($key), FILTER_VALIDATE_BOOLEAN);
            }
        }
        $this->merge($merge);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'province_id' => ['nullable', 'array', 'max:20'],
            'province_id.*' => ['integer', 'exists:provinces,id'],
            'crop_variety_id' => ['nullable', 'array', 'max:20'],
            'crop_variety_id.*' => ['integer', 'exists:crop_varieties,id'],
            'crop_category_id' => ['nullable', 'array', 'max:10'],
            'crop_category_id.*' => ['integer', 'exists:crop_categories,id'],
            'status' => ['nullable', 'array'],
            'status.*' => [Rule::in(HarvestListing::STATUSES)],
            'quality_grade' => ['nullable', 'array'],
            'quality_grade.*' => [Rule::in(['A', 'B', 'C'])],
            'harvest_from' => ['nullable', 'date'],
            'harvest_to' => ['nullable', 'date', 'after_or_equal:harvest_from'],
            'min_volume' => ['nullable', 'numeric', 'min:0'],
            'max_volume' => ['nullable', 'numeric', 'gte:min_volume'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'gte:min_price'],
            'seller_id' => ['nullable', 'integer', 'exists:users,id'],
            'verified_only' => ['nullable', 'boolean'],
            'organic_only' => ['nullable', 'boolean'],
            'certified_only' => ['nullable', 'boolean'],
            'coop_pools_only' => ['nullable', 'boolean'],
            'include_pool_members' => ['nullable', 'boolean'],
            'certified_grade' => ['nullable', Rule::in(['export', 'grade_a', 'class_b', 'class_c'])],
            'cooperative_id' => ['nullable', 'integer', 'exists:cooperatives,id'],
            'trading_hub_id' => ['nullable', 'integer', 'exists:trading_hubs,id'],
            'sort' => ['nullable', Rule::in(self::SORTS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:60'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
