<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        \Log::info('telegram webhook: ' . print_r($request->all(), true));
        $update = Telegram::getWebhookUpdate();
        \Log::info('update: ' . print_r($update, true));

        $message = $update->getMessage();
        \Log::info('message: ' . print_r($message, true));

        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chatId = $message->getChat()->getId();
        \Log::info('chatId: ' . print_r($chatId, true));

        $text = trim($message->getText() ?? '');

        switch ($text) {

            case '/start':
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Bot scrape DKKD đã hoạt động 🚀"
                ]);
                break;

            case '/run':
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Đang chạy scraper..."
                ]);

                // dispatch job
                break;

            case '/status':
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Hệ thống đang hoạt động."
                ]);
                break;

            default:
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => "Lệnh không hợp lệ."
                ]);
        }

        return response()->json([
            'ok' => true
        ]);
    }
}