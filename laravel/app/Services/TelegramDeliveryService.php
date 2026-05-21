<?php

namespace App\Services;

use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramDeliveryService
{
    public function sendDocumentToTarget(array $params): mixed
    {
        return Telegram::bot($this->deliveryBotName())->sendDocument($params);
    }

    public function sendDocumentToSource(array $params): mixed
    {
        return Telegram::sendDocument($params);
    }

    protected function deliveryBotName(): string
    {
        $botName = (string) config('telegram.delivery_bot', 'delivery');
        
        if (!config("telegram.bots.{$botName}")) {
            \Illuminate\Support\Facades\Log::warning("TelegramDeliveryService: Bot [{$botName}] is not configured in config/telegram.php. Falling back to 'delivery'.");
            return 'delivery';
        }
        
        return $botName;
    }
}
