<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $campaignId = $this->route('campaign')?->id;
        // Present only on update. Without ignoring it, editing a row's price
        // would fail against the row's own (campaign_id, product_id) pair —
        // the product has not changed, so it always looks like a duplicate.
        $campaignProductId = $this->route('campaignProduct')?->id;

        return [
            'product_id'    => [
                'required',
                'numeric',
                'exists:products,id',
                // Matches the unique index on (campaign_id, product_id), so a
                // duplicate comes back as a field error rather than a 500.
                Rule::unique('campaign_products', 'product_id')
                    ->ignore($campaignProductId)
                    ->where(fn($query) => $query->where('campaign_id', $campaignId)),
            ],
            // Hand-entered, never prefilled from the retail price. `gt:0`
            // rather than `min:0`: a campaign price of zero would hand the
            // product away for free.
            'special_price' => ['required', 'numeric', 'gt:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'product_id.required'    => 'The product field is required',
            'product_id.unique'      => 'This product is already in the campaign',
            'special_price.required' => 'The special price field is required',
            'special_price.gt'       => 'The special price must be greater than 0',
        ];
    }
}
