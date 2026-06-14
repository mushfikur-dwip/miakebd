<?php

namespace App\Http\Requests;

use App\Enums\Ask;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TelegramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'telegram_status'    => ['required', 'numeric', Rule::in([Ask::YES, Ask::NO])],
            'telegram_bot_token' => ['nullable', 'string', 'max:255'],
            'telegram_chat_id'   => ['nullable', 'string', 'max:255'],
        ];
    }
}
