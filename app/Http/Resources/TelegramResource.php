<?php

namespace App\Http\Resources;

use App\Enums\Ask;
use Illuminate\Http\Resources\Json\JsonResource;

class TelegramResource extends JsonResource
{
    public $info;

    public function __construct($info)
    {
        parent::__construct($info);
        $this->info = $info;
    }

    public function toArray($request): array
    {
        return [
            'telegram_status'    => $this->info['telegram_status'] ?? Ask::NO,
            'telegram_bot_token' => $this->info['telegram_bot_token'] ?? '',
            'telegram_chat_id'   => $this->info['telegram_chat_id'] ?? '',
        ];
    }
}
