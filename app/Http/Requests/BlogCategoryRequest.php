<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('blogCategory')?->id;

        return [
            'name'             => ['required', 'string', 'max:190', Rule::unique('blog_categories', 'name')->ignore($id)],
            'description'      => ['nullable', 'string', 'max:500'],
            'meta_title'       => ['nullable', 'string', 'max:190'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords'    => ['nullable', 'string', 'max:1000'],
            'priority'         => ['nullable', 'numeric'],
            'status'           => ['required', 'numeric'],
            'image'            => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ];
    }
}
