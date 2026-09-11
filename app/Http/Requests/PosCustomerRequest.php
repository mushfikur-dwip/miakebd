<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The till's "add customer" form.
 *
 * Not CustomerRequest, which the admin Customers page uses to create a real
 * site account and so requires an email and a password. The till only records
 * who bought: a name, and the phone the POS order SMS goes to. There is no
 * password because the row is not an account - see
 * CustomerService::storePosCustomer().
 */
class PosCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:190'],
            // Unique, as on the admin form: a number already on file is that
            // customer, and a second row would split their purchase history.
            'phone'        => ['required', 'string', 'max:20', Rule::unique('users', 'phone')],
            'country_code' => ['required', 'string', 'max:20'],
            // Optional, but still unique: two rows sharing an email would make
            // the storefront's email lookups ambiguous.
            'email'        => ['nullable', 'email', 'max:190', Rule::unique('users', 'email')],
            'status'       => ['required', 'numeric', 'max:24'],
        ];
    }
}
