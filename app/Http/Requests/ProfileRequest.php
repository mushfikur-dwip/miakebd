<?php

namespace App\Http\Requests;

use App\Enums\Ask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileRequest extends FormRequest
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
    public function rules()
    {
        // Both uniqueness checks skip guest-checkout rows, matching
        // SignupRequest. A merged customer keeps leftover guest rows carrying
        // the same phone number, and an unscoped rule collides with them on
        // every save — locking that customer out of their own profile forever.
        //
        // The inner where() is required, not cosmetic: this closure is appended
        // to a query that already filters on the column, so an un-nested
        // orWhereNull would bind as "(phone = X AND ...) OR is_guest IS NULL"
        // and match unrelated rows.
        $notGuest = fn($query) => $query->where(
            fn($builder) => $builder->where('is_guest', '!=', Ask::YES)->orWhereNull('is_guest')
        );

        return [
            'name'    => ['required', 'string', 'max:190'],
            'email'        => [
                'required',
                'email',
                'max:190',
                Rule::unique("users", "email")->where($notGuest)->ignore(auth()->user()->id)
            ],
            'phone'        => ['required', 'string', 'max:20', Rule::unique("users", "phone")->where($notGuest)->ignore(auth()->user()->id)],
            'country_code' => ['required', 'string', 'max:20'],
            'image'        => ['nullable', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }
}
