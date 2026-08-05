<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('blogTag')?->id;

        return [
            'name'             => ['required', 'string', 'max:190', Rule::unique('blog_tags', 'name')->ignore($id)],
            'description'      => ['nullable', 'string', 'max:500'],
            'meta_title'       => ['nullable', 'string', 'max:190'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords'    => ['nullable', 'string', 'max:2000'],
            'priority'         => ['nullable', 'numeric'],
            'status'           => ['required', 'numeric'],
        ];
    }
}
