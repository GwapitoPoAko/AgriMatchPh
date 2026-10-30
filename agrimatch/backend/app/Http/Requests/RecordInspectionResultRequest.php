<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RecordInspectionResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('record', $this->route('inspection'));
    }

    public function rules(): array
    {
        return [
            'result' => ['required', Rule::in(['passed', 'failed'])],
            'certified_grade' => ['required_if:result,passed', 'nullable', Rule::in(['export', 'grade_a', 'class_b', 'class_c'])],
            'sample_size_kg' => ['required', 'numeric', 'min:1', 'max:5000'],
            'moisture_pct' => ['nullable', 'numeric', 'between:0,100'],
            'defect_pct' => ['required', 'numeric', 'between:0,100'],
            'foreign_matter_pct' => ['nullable', 'numeric', 'between:0,100'],
            'brix' => ['nullable', 'numeric', 'between:0,40'],
            'pesticide_residue_passed' => ['required', 'boolean'],
            'remarks' => ['required_if:result,failed', 'nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->input('result') === 'passed' && ! $this->boolean('pesticide_residue_passed')) {
                    $validator->errors()->add('pesticide_residue_passed', 'A lot that fails the residue test cannot pass inspection.');
                }
                if ($this->input('certified_grade') === 'export' && (float) $this->input('defect_pct') > 5) {
                    $validator->errors()->add('certified_grade', 'Export quality allows at most 5% defects.');
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('remarks'))) {
            $this->merge(['remarks' => trim(strip_tags($this->input('remarks')))]);
        }
    }
}
