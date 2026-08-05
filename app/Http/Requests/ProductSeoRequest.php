<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductSeoRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'product_id'   => ['required', 'numeric'],
            'title'        => ['required', 'string', 'max:190'],
            'description'  => ['required', 'string', 'max:5000'],
            // The admin panel posts this as a JSON array string built by
            // vue-tags-input, e.g. ["vaseline","vaseline price in bangladesh"].
            // `max:190` counted CHARACTERS of that JSON, not keywords, so a
            // normal six-keyword set (~165 chars average across the 440 rows
            // already stored, 17 of them past 190) failed validation and the
            // whole SEO tab silently refused to save. The column is LONGTEXT;
            // 2000 is a sane ceiling that fits ~40 keywords.
            'meta_keyword' => ['required', 'string', 'max:2000'],
            'image'        => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048']
        ];
    }
}
