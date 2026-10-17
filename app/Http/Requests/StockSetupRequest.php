<?php

namespace App\Http\Requests;

use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Turning the "stock cannot be zero" switch on or off.
 *
 * The switch decides whether the shop may sell what it does not have, so it is
 * behind the branch cash PIN - the same PIN that takes money out of a drawer.
 * outlet_id is the branch the PIN is checked against, and the branch a wrong
 * try is counted against (see CashPinService's lockout).
 */
class StockSetupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outlet_id'            => [
                'required',
                'integer',
                Rule::exists('outlets', 'id')->where('status', Status::ACTIVE),
            ],
            'stock_cannot_be_zero' => ['required', 'boolean'],
            'pin'                  => ['required', 'string', 'max:8'],
        ];
    }

    public function attributes(): array
    {
        return [
            'stock_cannot_be_zero' => 'switch',
            'pin'                  => 'PIN',
            'outlet_id'            => 'branch',
        ];
    }
}
