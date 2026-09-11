<?php

namespace App\Http\Requests;

use App\Models\User;

use App\Enums\Activity;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Rules\ValidJsonOrder;
use App\Enums\PosPaymentMethod;
use Illuminate\Validation\Rule;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Foundation\Http\FormRequest;

class PosOrderRequest extends FormRequest
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
            'customer_id'        => ['required', 'numeric'],
            // The employee the sale is credited to. Optional, so API clients that
            // predate it keep working; when sent it must be an active employee -
            // never a customer or an admin - or the Employees figures would lie.
            'sales_by_id'        => ['nullable', 'integer', function ($attribute, $value, $fail) {
                if (!User::employees()->where('status', Status::ACTIVE)->whereKey($value)->exists()) {
                    $fail(trans('validation.exists', ['attribute' => 'sale by']));
                }
            }],
            'outlet_id'          => ['required', 'numeric', Rule::exists('outlets', 'id')->where('status', Status::ACTIVE)],
            'subtotal'           => ['required', 'numeric'],
            'discount'           => ['nullable', 'numeric'],
            'tax'                => ['required', 'numeric'],
            'total'              => ['required', 'numeric'],
            'order_type'         => ['required', 'numeric'],
            'source'             => ['required', 'numeric'],
            'products'           => ['required', 'json', new ValidJsonOrder],
            'pos_payment_method' => ['required', 'numeric'],
            'pos_payment_note'   => request('pos_payment_method') === PosPaymentMethod::CARD || request('pos_payment_method') === PosPaymentMethod::MOBILE_BANKING || request('pos_payment_method') === PosPaymentMethod::OTHER ? (request('pos_payment_method') === PosPaymentMethod::CARD ? ['required', 'numeric', 'min_digits:4', 'max_digits:4'] : ['required', 'string']) : ['nullable', 'string'],
            'pos_received_amount' => request('pos_payment_method') === PosPaymentMethod::CASH ? ['required', 'numeric', 'gte:total', 'between:0,999999999999'] : ['nullable', 'numeric'],
        ];
    }
    public function messages()
    {
        return [
            'pos_payment_note.required'    => request('pos_payment_method') == PosPaymentMethod::CARD ? 'Last 4 digits of card is required' : (request('pos_payment_method') == PosPaymentMethod::MOBILE_BANKING ? 'Transaction ID field is required' : 'Payment note field is required'),
            'pos_received_amount.required' => 'The received amount field is required'
        ];
    }
}
