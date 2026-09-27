<?php

namespace App\Http\Requests;

use App\Services\CashLedgerService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every money movement on the Cash Calculation page, validated per action.
 * The routes differ only in which of these fields they need, so one request
 * reads the action from the route and keeps all the rules side by side.
 */
class CashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $outlet  = ['required', 'integer', Rule::exists('outlets', 'id')];
        $account = ['required', 'integer', 'between:1,7'];
        $amount  = ['required', 'numeric', 'gt:0', 'max:999999999'];
        $pin     = ['required', 'string', 'max:8'];
        $note    = ['required', 'string', 'max:500'];

        return match ($this->route()->getActionMethod()) {
            'add'       => ['outlet_id' => $outlet, 'account' => $account, 'amount' => $amount, 'note' => $note],
            'mfs'       => [
                'outlet_id' => $outlet,
                'provider'  => ['required', Rule::in(array_keys(CashLedgerService::MFS_KINDS))],
                'kind'      => ['required', Rule::in(is_string($this->input('provider'))
                    ? (CashLedgerService::MFS_KINDS[$this->input('provider')] ?? [])
                    : [])],
                'amount'    => $amount,
                'reference' => ['nullable', 'string', 'max:100'],
                'party'     => ['nullable', 'string', 'max:190'],
                'note'      => ['nullable', 'string', 'max:500'],
            ],
            'count'     => [
                'outlet_id'       => $outlet,
                'account'         => $account,
                'counted'         => ['required_without:denominations', 'nullable', 'numeric', 'min:0', 'max:999999999'],
                'denominations'   => ['nullable', 'array'],
                'denominations.*' => ['integer', 'min:0', 'max:1000000'],
            ],
            'withdraw'  => [
                'outlet_id' => $outlet,
                'account'   => $account,
                'amount'    => $amount,
                'party'     => ['required', 'string', 'max:190'],
                'note'      => $note,
                'pin'       => $pin,
            ],
            'transfer'  => [
                'outlet_id'    => $outlet,
                'from_account' => $account,
                'to_account'   => [...$account, 'different:from_account'],
                'amount'       => $amount,
                'note'         => $note,
                'pin'          => $pin,
            ],
            'reverse'   => ['note' => $note, 'pin' => $pin],
            'mfsToggle' => ['outlet_id' => $outlet, 'enabled' => ['required', 'boolean'], 'pin' => $pin],
            'changePin' => [
                'outlet_id'   => $outlet,
                'current_pin' => $pin,
                'new_pin'     => ['required', 'digits_between:5,8', 'confirmed'],
            ],
            default     => [],
        };
    }

    public function attributes(): array
    {
        return [
            'party'        => $this->route()->getActionMethod() === 'withdraw' ? 'given to' : 'customer number',
            'from_account' => 'from',
            'to_account'   => 'to',
            'new_pin'      => 'new PIN',
            'current_pin'  => 'current PIN',
            'pin'          => 'PIN',
        ];
    }
}
