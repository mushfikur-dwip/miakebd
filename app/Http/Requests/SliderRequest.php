<?php

namespace App\Http\Requests;

use App\Enums\SliderPosition;
use Illuminate\Foundation\Http\FormRequest;
use App\Rules\SafeLink;
use Illuminate\Validation\Rule;

class SliderRequest extends FormRequest
{
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
     * The admin panel is a SPA, so a browser tab left open across this deploy
     * still runs the old form and sends no position. Defaulting here rather
     * than failing keeps that tab working, and HERO is what those rows were.
     */
    protected function prepareForValidation(): void
    {
        if (!$this->filled('position')) {
            $this->merge(['position' => SliderPosition::HERO]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [
            // Optional: a banner's words are usually in the picture itself.
            'title'        => [
                'nullable',
                'string',
                'max:190',
                Rule::unique("sliders", "title")->ignore($this->route('slider.id'))
            ],
            'description' => ['nullable'],
            'status'      => ['required', 'numeric'],
            // Was saved straight off the request without ever being validated.
            // SafeLink is the important part: this value is rendered into an
            // href on every storefront page, so a "javascript:" scheme here is
            // stored XSS against every shopper.
            'link'        => ['nullable', 'string', 'max:500', new SafeLink],
            'position'    => [
                'required',
                'numeric',
                Rule::in([SliderPosition::HERO, SliderPosition::GRID, SliderPosition::WIDE])
            ],
            'image'       => $this->route('slider.id') ? ['nullable', 'image', 'mimes:jpg,jpeg,png', 'max:2048'] : ['required', 'image', 'mimes:jpg,jpeg,png', 'max:2048'],
        ];
    }
}
