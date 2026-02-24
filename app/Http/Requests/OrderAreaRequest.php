<?php

namespace App\Http\Requests;

use App\Models\OrderArea;
use Illuminate\Foundation\Http\FormRequest;

class OrderAreaRequest extends FormRequest
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
            'country'       => ['required', 'string', 'max:900'],
            'state'         => ['required', 'string', 'max:900'],
            'city'          => ['nullable', 'string', 'max:900'],
            'shipping_cost' => ['required', 'string', 'max:900'],
            'status'        => ['required', 'numeric', 'max:24'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $exists = OrderArea::where('country', $this->country)->where('state', $this->state)->whereNot('id', $this->route('orderArea.id'))->first();
            if ($exists) {
                $validator->getMessageBag()->add('state', trans('all.message.country_exist'));
            }
        });
    }
}