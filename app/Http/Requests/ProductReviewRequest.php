<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductReviewRequest extends FormRequest
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
        // 'images[]' as a rule key never matched anything: PHP files the
        // browser's images[] fields under `images`, so every upload skipped
        // validation. An .html or .svg "photo" was then served from this
        // origin by /storage - stored XSS that could read the admin's token
        // out of localStorage. The array and its members are validated now.
        //
        // star was unbounded, so one review could carry 1000 stars and drag a
        // product's average wherever it liked.
        return [
            'product_id' => ['required', 'numeric', 'exists:products,id'],
            'star'       => ['required', 'numeric', 'between:0,5'],
            'review'     => ['required', 'string', 'max:5000'],
            'images'     => ['nullable', 'array', 'max:5'],
            'images.*'   => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
