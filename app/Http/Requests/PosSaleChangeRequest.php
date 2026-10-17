<?php

namespace App\Http\Requests;

use App\Enums\PosPaymentMethod;
use App\Services\PosSaleService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Reverse a till sale, or change its payment method. Each needs a reason and
 * a PIN; a new method also needs what the POS asks for when it is chosen
 * there - a card's last 4 digits, a mobile banking TrxID, a note for "Other".
 */
class PosSaleChangeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $reason = ['required', 'string', 'max:500'];
        $pin    = ['required', 'string', 'max:8'];

        return match ($this->route()->getActionMethod()) {
            'reverse'      => ['reason' => $reason, 'pin' => $pin],
            'changeMethod' => [
                'pos_payment_method' => ['required', 'integer', Rule::in(PosSaleService::METHODS)],
                'pos_payment_note'   => match ((int) $this->input('pos_payment_method')) {
                    PosPaymentMethod::CARD           => ['required', 'digits:4'],
                    PosPaymentMethod::MOBILE_BANKING => ['required', 'string', 'max:100'],
                    PosPaymentMethod::OTHER          => ['required', 'string', 'max:200'],
                    default                          => ['nullable', 'string', 'max:200'],
                },
                'reason'             => $reason,
                'pin'                => $pin,
            ],
            default        => [],
        };
    }

    public function messages(): array
    {
        return match ((int) $this->input('pos_payment_method')) {
            PosPaymentMethod::CARD           => ['pos_payment_note.*' => 'The last 4 digits of the card are required.'],
            PosPaymentMethod::MOBILE_BANKING => ['pos_payment_note.*' => 'The transaction ID is required.'],
            default                          => [],
        };
    }

    public function attributes(): array
    {
        return [
            'pos_payment_method' => 'payment method',
            'pos_payment_note'   => 'payment note',
            'pin'                => 'PIN',
        ];
    }
}
