<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddressRequest extends FormRequest
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
     * Same normalisation as GuestStartRequest, so an address carries the number
     * the way the customer's account does: "01712345678", "+880 1712-345678"
     * and a number typed in Bengali digits are all stored as "1712345678".
     * Kept as typed before this, the order screens showed "+880 01712345678".
     */
    protected function prepareForValidation(): void
    {
        $phone = strtr((string) $this->input('phone'), [
            '০' => '0', '১' => '1', '২' => '2', '৩' => '3', '৪' => '4',
            '৫' => '5', '৬' => '6', '৭' => '7', '৮' => '8', '৯' => '9',
        ]);
        $phone = preg_replace('/[^0-9]/', '', $phone);

        if ($this->isBangladeshi() && str_starts_with($phone, '880')) {
            $phone = substr($phone, 3);
        }

        $this->merge([
            'phone'     => ltrim($phone, '0'),
            'full_name' => trim((string) $this->input('full_name')),
            'address'   => trim((string) $this->input('address')),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'full_name'    => ['required', 'string', 'max:190'],
            'email'        => ['nullable', 'string', 'max:190'],
            'country_code' => ['required', 'string', 'max:28'],
            // A Bangladeshi mobile is 1 + operator digit 3-9 + 8 digits once
            // the leading 0 is gone; anything else cannot be called or sent
            // the order SMS, and the rider has no way to reach the customer.
            'phone'        => $this->isBangladeshi()
                ? ['required', 'string', 'regex:/^1[3-9][0-9]{8}$/']
                : ['required', 'string', 'max:20'],
            'country'      => ['required', 'string', 'max:100'],
            'state'        => ['required', 'string', 'max:100'],
            'city'         => ['nullable', 'string', 'max:100'],
            'zip_code'     => ['nullable', 'string'],
            'address'      => ['required', 'string', 'max:500'],
        ];
    }

    public function messages()
    {
        return [
            'address.required' => 'The full address field is required.',
            'state.required'   => 'Please select your district.',
            'phone.regex'      => 'Please enter a valid mobile number.',
        ];
    }

    // The column is `state`, but every screen calls it the district.
    public function attributes(): array
    {
        return [
            'state'   => 'district',
            'address' => 'full address',
        ];
    }

    private function isBangladeshi(): bool
    {
        return in_array(trim((string) $this->input('country_code')), ['+880', '880', ''], true);
    }
}
