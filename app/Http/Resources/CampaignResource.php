<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class CampaignResource extends JsonResource
{
    /**
     * @param \Illuminate\Http\Request $request
     */
    public function toArray($request): array
    {
        return [
            'id'     => $this->id,
            'name'   => $this->name,
            'slug'   => $this->slug,
            'type'   => $this->type,
            'status' => $this->status,

            // ISO-8601 *with offset* (…+06:00), not "Y-m-d H:i:s". A bare
            // datetime string is parsed by the browser as local time, so a
            // customer outside Asia/Dhaka would see a countdown hours off.
            'starts_at' => optional($this->starts_at)->toIso8601String(),
            'ends_at'   => optional($this->ends_at)->toIso8601String(),

            // Preformatted for admin tables, which show the store's own zone.
            'starts_at_label' => optional($this->starts_at)->format('d M Y, h:i A'),
            'ends_at_label'   => optional($this->ends_at)->format('d M Y, h:i A'),

            // Datepicker round-trip format for the edit drawer.
            'starts_at_input' => optional($this->starts_at)->format('Y-m-d H:i:s'),
            'ends_at_input'   => optional($this->ends_at)->format('Y-m-d H:i:s'),

            'is_running' => $this->is_running,

            // Seconds remaining, computed server-side so the countdown does
            // not inherit a wrong client clock. 0 once the window has closed.
            //
            // Plain timestamp subtraction rather than diffInSeconds(): Carbon 3
            // returns a float there, which would tick the countdown in
            // fractions of a second, and its sign convention is easy to get
            // backwards — a wrong sign here silently shows 0 forever.
            'ends_in_seconds' => $this->is_running
                ? max(0, $this->ends_at->getTimestamp() - now()->getTimestamp())
                : 0,
        ];
    }
}
