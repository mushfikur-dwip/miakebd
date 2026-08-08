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
        // Second guard, independent of the service. toArray() is invoked while
        // the response is being rendered, long after the controller's
        // try/catch has returned, so anything thrown here is an unhandled 500
        // the admin sees only as "Server Error". A resource with no underlying
        // model must degrade to empty fields, never blow up.
        if (!$this->resource) {
            return [
                'id' => '',
                'product_id' => '',
                'title' => '',
                'description' => '',
                'meta_keyword' => [],
                'thumb' => asset('images/default/seo/thumb.png'),
                'cover' => asset('images/default/seo/cover.png'),
            ];
        }

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
