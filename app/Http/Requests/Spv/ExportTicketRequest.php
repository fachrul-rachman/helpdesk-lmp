<?php

namespace App\Http\Requests\Spv;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ExportTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'period' => ['required', Rule::in(['week', 'month', 'year', 'custom'])],
            'start_date' => ['nullable', 'required_if:period,custom', 'date_format:Y-m-d'],
            'end_date' => [
                'nullable',
                'required_if:period,custom',
                'date_format:Y-m-d',
                'after_or_equal:start_date',
            ],
        ];
    }
}
