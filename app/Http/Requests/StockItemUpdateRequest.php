<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id'   => ['required', 'numeric', Rule::exists('products', 'id')],
            'variation_id' => ['nullable', 'numeric'],
            'stocks'       => ['required', 'json'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $stocks = json_decode($this->get('stocks'), true);

            if (!is_array($stocks) || !count($stocks)) {
                $validator->errors()->add('stocks', trans('all.message.product_invalid'));
                return;
            }

            foreach ($stocks as $stock) {
                // A branch count may legitimately be set to zero, and a
                // negative one is what an over-sold branch actually holds, so
                // the only thing rejected here is a non-number.
                if (!isset($stock['quantity']) || !is_numeric($stock['quantity'])) {
                    $validator->errors()->add('global', trans('all.message.product_quantity_invalid'));
                    return;
                }
            }
        });
    }
}
