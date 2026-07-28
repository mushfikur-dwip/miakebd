<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProductSeoResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param  \Illuminate\Http\Request  $request
     */
    public function toArray($request): array
    {
        return [
            'id' => $this->id ?? '',
            'product_id' => $this->product_id ?? '',
            'title' => $this->title ?? '',
            'description' => $this->description ?? '',
            'meta_keyword' => $this->keywords(),
            'thumb' => $this->thumb ?? '',
            'cover' => $this->cover ?? '',
        ];
    }

    private function keywords(): array
    {
        if (is_array($this->meta_keyword)) {
            return $this->meta_keyword;
        }

        $decoded = json_decode((string) $this->meta_keyword, true);

        return is_array($decoded) ? array_values($decoded) : [];
    }
}
