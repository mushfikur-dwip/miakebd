<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CashQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The whole-page answer falls back to the first branch.
            'outlet_id' => [$this->route()->getActionMethod() === 'page' ? 'nullable' : 'required', 'integer', Rule::exists('outlets', 'id')],
            'date'      => ['nullable', 'date_format:Y-m-d'],
            'from'      => ['nullable', 'date_format:Y-m-d'],
            'to'        => ['nullable', 'date_format:Y-m-d'],
            'account'   => ['nullable', 'integer', 'between:1,9'],
            'type'      => ['nullable', 'integer', 'between:1,12'],
            'page'      => ['nullable', 'integer', 'min:1'],
            'per_page'  => ['nullable', 'integer', 'between:1,100'],
            // The watch list: today, or the last 7 days (the default).
            'period'    => ['nullable', Rule::in(['today', 'week'])],
        ];
    }
}
