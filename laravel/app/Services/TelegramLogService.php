<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramLogService
{
    /**
     * Log the exception and optionally notify the user via Telegram
     */
    public function logException(\Throwable $e, ?string $chatId = null, string $context = 'System'): void
    {
        $message = "[$context] Error: " . $e->getMessage();
        
        Log::error($message, [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        if ($chatId) {
            try {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "❌ Có lỗi xảy ra trong hệ thống:\n" . $e->getMessage()
                ]);
            } catch (\Exception $telegramEx) {
                Log::error("Failed to send error message to Telegram: " . $telegramEx->getMessage());
            }
        }
    }
}
