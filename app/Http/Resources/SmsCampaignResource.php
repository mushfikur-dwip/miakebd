<?php

namespace App\Http\Resources;

use App\Enums\SmsCampaignStatus;
use Illuminate\Http\Resources\Json\JsonResource;

class SmsCampaignResource extends JsonResource
{
    public function toArray($request): array
    {
        $processed = $this->sent_count + $this->failed_count;

        return [
            'id'           => $this->id,
            'title'        => $this->title,
            'message'      => $this->message,
            'status'       => $this->status,
            'total_count'  => $this->total_count,
            'sent_count'   => $this->sent_count,
            'failed_count' => $this->failed_count,
            'pending_count' => max(0, $this->total_count - $processed),
            // Sent on the wire so the progress bar and the "stop" button do not
            // have to re-derive the same rule from four separate numbers.
            'is_finished'  => $this->status === SmsCampaignStatus::COMPLETED,
            'created_at'   => $this->created_at?->toDateTimeString(),
        ];
    }
}
