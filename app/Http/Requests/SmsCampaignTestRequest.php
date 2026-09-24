<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SmsCampaignTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country_code' => ['nullable', 'string', 'max:10'],
            'phone'        => ['required', 'string', 'max:30'],
            'message'      => ['required', 'string', 'max:800'],
            'name'         => ['nullable', 'string', 'max:190'],
        ];
    }
}
