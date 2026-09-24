<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SmsCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title'   => ['nullable', 'string', 'max:190'],
            // A long message is billed as several SMS parts, so the cap keeps a
            // pasted essay from quietly costing five times what was expected.
            'message' => ['required', 'string', 'max:800'],
        ];
    }
}
