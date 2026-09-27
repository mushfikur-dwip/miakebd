<?php

namespace App\Http\Resources;

use App\Support\VideoEmbed;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductVideoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {

        return [
            'id'             => $this->id,
            'product_id'     => $this->product_id,
            'video_provider' => $this->video_provider,
            'provider_name'  => trans('videoProvider.' . $this->video_provider),
            'link'           => $this->link,
            // What the storefront frames: the provider's player, whatever
            // form of the link the admin pasted.
            'embed_url'      => VideoEmbed::url((int) $this->video_provider, (string) $this->link),
            'orientation'    => $this->orientation,
            // The frame's shape on the storefront: 9:16 when true, else 16:9.
            'portrait'       => VideoEmbed::isPortrait((int) $this->video_provider, (string) $this->link, $this->orientation ?: null),
        ];
    }
}
