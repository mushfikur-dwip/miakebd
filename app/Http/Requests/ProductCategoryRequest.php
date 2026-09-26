<?php

namespace App\Http\Requests;

use App\Models\ProductCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductCategoryRequest extends FormRequest
{
    /**
     * Invisible control characters out of the name. "Skin Care" was stored as
     * "\x1DSkin Care" - pasted in from somewhere - and the stray byte went into
     * the page title, the h1 and the breadcrumb.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge([
                'name' => trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', '', (string) $this->input('name'))),
            ]);
        }
    }

    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            'name'        => [
                'required',
                'string',
                'max:190',
                Rule::unique("product_categories", "name")->where('parent_id', $this->input('parent_id'))->ignore($this->route('productCategory.id'))
            ],
            'parent_id'   => ['nullable', 'string', 'max:900'],
            'description' => ['nullable', 'string', 'max:900'],
            'status'      => ['required', 'numeric', 'max:24'],
            'is_featured' => ['nullable', 'boolean'],
            'image'       => ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048']
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->route('productCategory.id')) {
                    if ($this->input('parent_id') != 'NULL') {
                        if ($this->input('parent_id') == $this->route('productCategory.id')) {
                            $validator->errors()->add(
                                'parent_id',
                                'The parent filed and edit field is same data.'
                            );
                        } else {
                            $status = false;
                            $productCategoryParents = ProductCategory::find($this->input('parent_id'))->ancestors()->get();
                            if ($productCategoryParents) {
                                foreach ($productCategoryParents as $productCategoryParent) {
                                    if ($productCategoryParent->id == $this->route('productCategory.id')) {
                                        $status = true;
                                    }
                                }
                            }
                            if ($status) {
                                $validator->errors()->add(
                                    'parent_id',
                                    'You do not select this parent. because the paren already to add it for the children.'
                                );
                            }
                        }
                    }
                }
            }
        ];
    }
}
