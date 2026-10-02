<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * For settings that are copied into .env (company name, mail settings, the
 * licence key).
 *
 * The env editor wraps a value containing spaces in double quotes but never
 * escapes it. A quote or backslash inside then leaves .env unparseable and
 * every page answers 500; a line break appends a line of the author's choosing
 * (APP_DEBUG=true); ${OTHER_KEY} makes phpdotenv substitute another variable,
 * e.g. the database password, into a setting that is shown on screen.
 */
class EnvSafe implements ValidationRule
{
    public static function isSafe(?string $value): bool
    {
        if ($value === null || $value === '') {
            return true;
        }

        return !preg_match('/["\\\\\r\n\0]/', $value) && !str_contains($value, '${');
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || !self::isSafe($value)) {
            $fail('The :attribute may not contain quotes, backslashes, line breaks or ${…}.');
        }
    }
}
