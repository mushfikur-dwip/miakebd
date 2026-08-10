<?php

namespace App\Http\Requests;

use App\Enums\StockAdjustmentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StockAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $type = (int) $this->get('type');

        return [
            'type'           => ['required', Rule::in([
                StockAdjustmentType::TRANSFER,
                StockAdjustmentType::ADD,
                StockAdjustmentType::REMOVE,
                StockAdjustmentType::RECALCULATE,
            ])],
            // Null is the unassigned pool, so only the destination of an ADD
            // and the source of a REMOVE have to name a real branch. A
            // recalculate may legitimately target the unassigned pool too.
            'from_outlet_id' => [$type === StockAdjustmentType::REMOVE ? 'required' : 'nullable', 'numeric', Rule::exists('outlets', 'id')],
            'to_outlet_id'   => [$type === StockAdjustmentType::ADD ? 'required' : 'nullable', 'numeric', Rule::exists('outlets', 'id')],
            'date'           => ['required', 'string'],
            'reference_no'   => ['nullable', 'string', 'max:255'],
            'note'           => ['nullable', 'string', 'max:1000'],
            'products'       => ['required', 'json'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $type = (int) $this->get('type');

            if ($type === StockAdjustmentType::TRANSFER && $this->get('from_outlet_id') == $this->get('to_outlet_id')) {
                $validator->errors()->add('to_outlet_id', trans('all.message.stock_adjustment_same_outlet'));
                return;
            }

            $products = json_decode($this->get('products'), true);

            if (!is_array($products) || !count($products)) {
                $validator->errors()->add('products', trans('all.message.product_invalid'));
                return;
            }

            foreach ($products as $product) {
                if (blank($product['product_id'] ?? null)) {
                    $validator->errors()->add('products', trans('all.message.product_invalid'));
                    return;
                }

                // A recalculate may set a branch to zero - that is the whole
                // point of "the number I type is the stock". The delta types
                // still need at least one unit to move.
                $minimum = $type === StockAdjustmentType::RECALCULATE ? 0 : 1;

                if (!isset($product['quantity']) || !is_numeric($product['quantity']) || (int) $product['quantity'] < $minimum) {
                    $validator->errors()->add('global', trans('all.message.product_quantity_invalid'));
                    return;
                }
            }
        });
    }
}
