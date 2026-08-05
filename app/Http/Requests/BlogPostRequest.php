<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BlogPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $id = $this->route('blogPost')?->id;

        return [
            'title'            => ['required', 'string', 'max:190', Rule::unique('blog_posts', 'title')->ignore($id)],
            // Optional. The service slugifies the title when this is blank, but
            // an admin can pin a short URL for a long headline — and once a
            // post is indexed, the slug must stay editable rather than silently
            // tracking every title tweak.
            'slug'             => ['nullable', 'string', 'max:190', 'regex:/^[A-Za-z0-9\-_.]+$/', Rule::unique('blog_posts', 'slug')->ignore($id)],
            'blog_category_id' => ['nullable', 'numeric', 'exists:blog_categories,id'],
            'excerpt'          => ['nullable', 'string', 'max:500'],
            'content'          => ['required', 'string'],
            'author_name'      => ['nullable', 'string', 'max:190'],
            'is_featured'      => ['nullable', 'boolean'],
            'published_at'     => ['nullable', 'date'],
            // Comma-separated blog_tags ids — see BlogPostService::syncTags()
            // for why it is not an array field.
            'tags'             => ['nullable', 'string', 'regex:/^[0-9,\s]*$/'],

            'meta_title'       => ['nullable', 'string', 'max:190'],
            'meta_description' => ['nullable', 'string', 'max:500'],
            'meta_keywords'    => ['nullable', 'string', 'max:1000'],
            'canonical_url'    => ['nullable', 'url', 'max:190'],
            'robots'           => ['nullable', 'string', 'max:100'],

            'status'           => ['required', 'numeric'],
            // 4MB: blog covers are wide hero images, and the 2MB product limit
            // rejected ordinary 1600px photos straight off a phone.
            'image'            => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
        ];
    }

    public function messages(): array
    {
        return [
            'slug.regex'             => 'The slug may only contain letters, numbers, dashes, underscores and dots.',
            'blog_category_id.exists' => 'The selected blog category no longer exists.',
        ];
    }
}
