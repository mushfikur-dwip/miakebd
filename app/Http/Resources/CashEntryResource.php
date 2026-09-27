<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use App\Services\CashLedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CashEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // Set by the controller: the running balance is a figure, and figures
        // are only for holders of cash-calculation_balance.
        $full = (bool) $request->attributes->get('cash_full');

        return [
            'id'             => $this->id,
            'account'        => $this->account,
            'account_name'   => CashLedgerService::ACCOUNT_NAMES[$this->account] ?? '',
            'type'           => $this->type,
            'amount'         => $this->amount,
            'balance_after'  => $full ? $this->balance_after : null,
            'order_id'       => $this->order_id,
            'reference'      => $this->reference,
            'party'          => $this->party,
            'note'           => $this->note,
            'group_ref'      => $this->group_ref,
            'reverses_id'    => $this->reverses_id,
            'reversed'       => $this->relationLoaded('reversedBy') && $this->reversedBy !== null,
            'reversible'     => in_array($this->type, CashLedgerService::REVERSIBLE, true),
            'creator'        => $this->creator?->name,
            'converted_date' => AppLibrary::datetime($this->created_at),
        ];
    }
}
