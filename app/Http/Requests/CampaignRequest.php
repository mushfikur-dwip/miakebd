<?php

namespace App\Http\Requests;

use App\Enums\CampaignType;
use App\Enums\Status;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // route('campaign') is the model on update and null on store; the id
        // is what the unique rule has to ignore so re-saving a campaign under
        // its own name does not fail.
        $campaignId = $this->route('campaign')?->id;

        return [
            'name'      => [
                'required',
                'string',
                'max:190',
                Rule::unique('campaigns', 'name')->ignore($campaignId),
            ],
            'type'      => ['required', 'numeric', Rule::in([CampaignType::FLASH, CampaignType::CLEARANCE])],
            'starts_at' => ['required', 'date'],
            // A campaign that ends before it starts is never running, so the
            // page would be permanently empty with no error to explain it.
            'ends_at'   => ['required', 'date', 'after:starts_at'],
            'status'    => ['required', 'numeric', Rule::in([Status::ACTIVE, Status::INACTIVE])],
        ];
    }
}
