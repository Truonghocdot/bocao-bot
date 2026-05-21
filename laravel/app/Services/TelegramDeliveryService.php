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
        return (string) config('telegram.delivery_bot', 'delivery');
    }
}
