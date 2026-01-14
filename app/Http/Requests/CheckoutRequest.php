<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckoutRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'items'           => 'required|array',
            'subtotal'        => 'required|numeric',
            'tax'             => 'required|numeric',
            'discount'        => 'required|numeric',
            'shipping_charge' => 'required|numeric',
            'total'           => 'required|numeric',
            'order_type'      => 'required|numeric',
            'payment_method'  => 'required|numeric',
            'wallet_discount' => 'nullable|numeric',
        ];
    }
}
