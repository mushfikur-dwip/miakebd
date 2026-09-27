<?php

namespace App\Http\Requests;

use App\Enums\VideoOrientation;
use App\Enums\VideoProvider;
use App\Support\FacebookVideoLink;
use App\Support\VideoEmbed;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductVideoRequest extends FormRequest
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
     * A Facebook video is stored as its reel address, whatever was pasted:
     * the reel or page-video link, Facebook's Embed code, or a Share link -
     * which Facebook's player cannot follow, so it is followed here instead.
     */
    protected function prepareForValidation(): void
    {
        $link = $this->input('link');
        if ((int) $this->input('video_provider') !== VideoProvider::FACEBOOK || !is_string($link)) {
            return;
        }

        $link = FacebookVideoLink::fromPasted($link);
        if ($id = FacebookVideoLink::videoId($link)) {
            $link = FacebookVideoLink::canonical($id);
        } elseif (FacebookVideoLink::isShortLink($link)) {
            $link = FacebookVideoLink::resolve($link) ?? $link;
        }

        $this->merge(['link' => $link]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules(): array
    {
        return [

            'video_provider' => ['required', 'numeric', Rule::in([
                VideoProvider::YOUTUBE, VideoProvider::DAILYMOTION, VideoProvider::VIMEO, VideoProvider::FACEBOOK,
            ])],
            'link'           => ['required', 'url', 'max:5000',
                Rule::unique("product_videos", "link")->where('product_id', $this->route('product.id'))->ignore($this->route('productVideo.id')),
                // What reaches here for Facebook is either a reel address or
                // something that could not be turned into one - which the
                // player would show as "Video unavailable".
                function ($attribute, $value, $fail) {
                    if ((int) $this->input('video_provider') !== VideoProvider::FACEBOOK) {
                        return;
                    }
                    if (FacebookVideoLink::isShortLink((string) $value)) {
                        $fail(trans('all.message.facebook_share_link'));
                    } elseif (!VideoEmbed::isFacebook((string) $value) || !FacebookVideoLink::videoId((string) $value)) {
                        $fail(trans('all.message.facebook_video_link'));
                    }
                },
            ],
            // Empty or 0 = work it out from the link.
            'orientation'    => ['nullable', 'integer', Rule::in([0, VideoOrientation::LANDSCAPE, VideoOrientation::PORTRAIT])],
        ];
    }
}
