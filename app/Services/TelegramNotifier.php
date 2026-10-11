<?php

namespace App\Services;

use App\Enums\Ask;
use Dipokhalder\Settings\Facades\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Posts a message to the shop's Telegram chat (Admin -> Settings -> Telegram).
 * Shared by the POS sale and online order alerts, so both always follow the
 * same switch, bot and chat.
 */
class TelegramNotifier
{
    /** Telegram is switched on and has a bot token and a chat to post to. */
    public function enabled(): bool
    {
        $settings = $this->settings();

        return (int) $settings['telegram_status'] === Ask::YES
            && !blank($settings['telegram_bot_token'])
            && !blank($settings['telegram_chat_id']);
    }

    /**
     * Sends an HTML message. Returns false - and only logs - when Telegram is
     * off or refuses it: an alert must never break the sale that caused it.
     *
     * @param string $what what the message is about, for the log
     */
    public function send(string $html, string $what): bool
    {
        if (!$this->enabled()) {
            return false;
        }

        $settings = $this->settings();
        $response = Http::timeout(8)->asForm()->post(
            'https://api.telegram.org/bot' . $settings['telegram_bot_token'] . '/sendMessage',
            [
                'chat_id'                  => $settings['telegram_chat_id'],
                'text'                     => $html,
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ]
        );

        if ($response->failed()) {
            Log::info("Telegram {$what} notification failed: " . $response->body());

            return false;
        }

        return true;
    }

    /** Text for an HTML message: Telegram refuses the whole message over one stray < or &. */
    public static function escape(?string $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function settings(): array
    {
        return array_merge([
            'telegram_status'    => Ask::NO,
            'telegram_bot_token' => '',
            'telegram_chat_id'   => '',
        ], Settings::group('telegram')->all());
    }
}
