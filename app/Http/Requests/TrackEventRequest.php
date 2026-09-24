<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A browser event being mirrored to the Conversions API.
 *
 * Deliberately narrow: an event name from a fixed list, ids that must exist,
 * and nothing else. No price, value or currency is accepted - those are read
 * from the database in the controller, because these numbers are what ad
 * bidding optimises on and a forgeable value would poison it.
 */
class TrackEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Purchase is missing on purpose: it is reported by the server
            // from the order itself (OrderObserver), so it can never be faked.
            'event'    => ['required', 'string', Rule::in(['ViewContent', 'AddToCart', 'InitiateCheckout'])],
            // Shared with the browser's copy so Meta can drop the duplicate.
            'event_id' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/'],

            'product_id' => ['required_if:event,ViewContent,AddToCart', 'nullable', 'integer', 'exists:products,id'],
            'quantity'   => ['nullable', 'integer', 'min:1', 'max:100'],

            'contents'            => ['required_if:event,InitiateCheckout', 'nullable', 'array', 'max:50'],
            'contents.*.id'       => ['required', 'integer', 'exists:products,id'],
            'contents.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],

            'source_url' => ['nullable', 'string', 'max:500'],
        ];
    }
}
