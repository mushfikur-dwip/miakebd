<?php

namespace App\Services;

use App\Enums\Ask;
use App\Http\Requests\TelegramRequest;
use App\Libraries\QueryExceptionLibrary;
use Dipokhalder\Settings\Facades\Settings;
use Exception;
use Illuminate\Support\Facades\Log;

class TelegramSettingService
{
    public function list(): array
    {
        try {
            return array_merge([
                'telegram_status'    => Ask::NO,
                'telegram_bot_token' => '',
                'telegram_chat_id'   => '',
            ], Settings::group('telegram')->all());
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }

    public function update(TelegramRequest $request): array
    {
        try {
            Settings::group('telegram')->set($request->validated());
            return $this->list();
        } catch (Exception $exception) {
            Log::info($exception->getMessage());
            throw new Exception(QueryExceptionLibrary::message($exception), 422);
        }
    }
}
