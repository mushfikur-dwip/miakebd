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
                // Units arriving, added to what the branch already holds:
                // stock is never reduced by hand. An old screen still sending
                // the count to set ("quantity") is refused, not misread.
                $add = $stock['add'] ?? null;
                if ($add === null || !is_numeric($add) || (float) $add < 0 || floor((float) $add) != (float) $add) {
                    $validator->errors()->add('global', trans('all.message.stock_add_whole_number'));
                    return;
                }
            }
        });
    }
}
