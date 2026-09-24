<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReturnAndRefundRequest extends FormRequest
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
            'return_reason_id' => ['required', 'numeric'],
            'note'             => ['nullable', 'string', 'max:5000'],
            'order_id'         => ['required', 'numeric'],
            'order_serial_no'  => ['required', 'string'],
            'products'         => ['required', 'json'],
            // Was keyed 'image[]', which matches nothing - PHP files image[]
            // fields under `image` - so any file type was accepted and served
            // back from /storage on this origin.
            'image'            => ['nullable', 'array', 'max:5'],
            'image.*'          => ['image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }

    public function messages(){
        return [
            "return_reason_id.required" => "The return reason field is required."
        ];
    }
}
