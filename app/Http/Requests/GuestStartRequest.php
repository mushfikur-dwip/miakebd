<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for starting a guest checkout.
 *
 * Deliberately minimal — the point of guest checkout is the least possible
 * typing, so nothing is required beyond a name and a reachable phone number.
 * Field names match SignupPhoneRequest so the same frontend phone input can be
 * reused without changes.
 */
class GuestStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The identity key for a guest is (phone, country_code) and it is compared
     * by exact string match, including by the check that refuses to hand out a
     * guest session for a number a real account already owns. Left free-form,
     * posting "880" or " +880" instead of "+880" walked straight past that
     * check and created a guest row shadowing a registered customer.
     */
    protected function prepareForValidation(): void
    {
        $countryCode = trim((string) $this->post('country_code'));

        if ($countryCode !== '') {
            $countryCode = '+' . ltrim(preg_replace('/[^0-9]/', '', $countryCode), '0');
        }

        $this->merge([
            'country_code' => $countryCode,
            // Local subscriber number only, so "01712..." and "1712..." are one
            // identity rather than two.
            'phone' => ltrim(preg_replace('/[^0-9]/', '', (string) $this->post('phone')), '0'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:120'],
            'phone'        => ['required', 'string', 'regex:/^[0-9]{6,15}$/'],
            'country_code' => ['required', 'string', 'regex:/^\+[0-9]{1,4}$/'],
            'email'        => ['nullable', 'email', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'         => 'Please enter your name',
            'phone.required'        => 'Please enter your phone number',
            'country_code.required' => 'Please select your country code',
        ];
    }
}
