<?php

namespace App\Http\Requests\Spv;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class TicketReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role === 'spv';
    }

    public function rules(): array
    {
        return [
            'section' => ['sometimes', Rule::in(['overview', 'sla', 'category', 'satisfaction'])],
            'start_date' => ['required', 'date_format:Y-m-d'],
            'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
            'division_id' => ['nullable', 'uuid'],
            'status' => ['nullable', Rule::in(['new', 'open', 'pending', 'on_progress', 'queue', 'solved', 'closed'])],
            'priority' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'has_request' => ['nullable', 'boolean'],
            'filter' => ['nullable', Rule::in(['active', 'unreviewed', 'fr_on_time', 'fr_late', 'resolution_on_time', 'resolution_late'])],
            'dimension' => ['nullable', Rule::in(['category', 'subcategory', 'site', 'zone'])],
            'value' => [
                'nullable', 'string', 'max:255',
                Rule::requiredIf(fn () => $this->filled('dimension') && ! $this->boolean('missing')),
                Rule::when(in_array($this->input('dimension'), ['category', 'subcategory'], true), ['uuid']),
            ],
            'missing' => ['nullable', 'boolean'],
            'rating_group' => ['nullable', Rule::in(['good', 'bad'])],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (CarbonImmutable::parse($this->string('start_date'))->diffInDays(CarbonImmutable::parse($this->string('end_date'))) > 366) {
                $validator->errors()->add('end_date', 'Rentang laporan maksimal satu tahun.');
            }
        }];
    }
}
