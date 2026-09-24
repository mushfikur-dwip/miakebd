<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaginateRequest extends FormRequest
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
        // order_column goes straight into orderBy() in ~30 services. Laravel
        // quotes it as an identifier, so it is not an injection, but it still
        // let a caller sort any list by any column - by password hash, say, to
        // learn how rows compare. A plain column name only, and never a
        // credential column. (order_type is left alone: several order lists
        // use it as the delivery/pickup/POS filter, not a sort direction.)
        return [
            'per_page'     => ['numeric', 'min:1', 'max:1000'],
            'order_column' => ['nullable', 'string', 'max:64', 'regex:/^(?!.*(password|token|secret))[A-Za-z_][A-Za-z0-9_.]*$/i'],
        ];
    }
}
