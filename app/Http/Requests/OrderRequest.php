<?php

namespace App\Http\Requests;

use App\Enums\Activity;
use App\Enums\Ask;
use App\Enums\OrderType;
use App\Rules\ValidJsonOrder;
use Illuminate\Validation\Rule;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;

class OrderRequest extends FormRequest
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
            'subtotal'        => ['required', 'numeric'],
            'discount'        => ['nullable', 'numeric'],
            'shipping_charge' => (int) request('order_type') == OrderType::DELIVERY ? ['required', 'numeric'] : ['nullable'],
            'tax'             => ['required', 'numeric'],
            'total'           => ['required', 'numeric'],
            'order_type'      => ['required', 'numeric'],
            'shipping_id'     => (int) request('order_type') == OrderType::DELIVERY ? ['required', 'numeric'] : ['nullable'],
            'billing_id'      => (int) request('order_type') == OrderType::DELIVERY ? ['required', 'numeric'] : ['nullable'],
            'outlet_id'       => (int) request('order_type') == OrderType::PICK_UP ? ['required', 'numeric', 'not_in:0'] : ['nullable'],
            'coupon_id'       => ['nullable', 'numeric'],
            'source'          => ['required', 'numeric'],
            'payment_method'  => ['required', 'numeric'],
            'products'        => ['required', 'json', new ValidJsonOrder]
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if (request('order_type') != OrderType::DELIVERY && request('order_type') != OrderType::PICK_UP) {
                $validator->errors()->add('order_type', 'This order type is disabled now you can try another order type right now or call the management.');
            }

            // Coupons require a real account. Blocking this only in the UI and
            // in coupon-checking would still leave the order endpoint able to
            // accept a coupon_id directly, and each guest checkout is a fresh
            // user row — so limit_per_user would count from zero every time.
            // auth('sanctum') for the same reason as CouponService: the guard
            // must be named explicitly or the token is not resolved here.
            $user = auth('sanctum')->user();

            if ($user && (int) $user->is_guest === Ask::YES && (int) request('coupon_id') > 0) {
                $validator->errors()->add('coupon_id', trans('all.message.coupon_requires_account'));
            }
        });
    }
}
