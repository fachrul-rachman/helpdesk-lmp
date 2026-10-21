<?php

namespace App\Http\Requests\Spv;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'division_id' => ['nullable', 'uuid'],
            'statuses' => ['sometimes', 'array', 'min:1', 'max:7'],
            'statuses.*' => ['required', 'distinct', Rule::in(['new', 'open', 'pending', 'on_progress', 'queue', 'solved', 'closed'])],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('period') !== 'custom' || $validator->errors()->isNotEmpty()) {
                return;
            }

            if (CarbonImmutable::parse($this->string('start_date'))->diffInDays(CarbonImmutable::parse($this->string('end_date'))) > 366) {
                $validator->errors()->add('end_date', 'Rentang ekspor maksimal satu tahun.');
            }
        }];
    }
}
