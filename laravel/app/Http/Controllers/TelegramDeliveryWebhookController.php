<?php

namespace App\Http\Controllers;

use App\Models\TelegramChat;
use App\Services\TelegramLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramDeliveryWebhookController extends Controller
{
    public function __construct(protected TelegramLogService $logService)
    {
    }

    public function __invoke(Request $request)
    {
        try {
            $payload = $request->all();
            $message = data_get($payload, 'message')
                ?: data_get($payload, 'edited_message')
                ?: data_get($payload, 'my_chat_member')
                ?: data_get($payload, 'chat_member');

            $chat = data_get($message, 'chat');

            if (! $chat) {
                return response()->json(['ok' => true]);
            }

            $chatId = (string) data_get($chat, 'id', '');
            if ($chatId === '') {
                return response()->json(['ok' => true]);
            }

            $chatType = data_get($chat, 'type');
            $record = TelegramChat::updateOrCreate(
                ['chat_id' => $chatId],
                [
                    'type' => $chatType,
                    'title' => data_get($chat, 'title'),
                    'username' => data_get($chat, 'username'),
                    'is_bot_member' => true,
                    'last_seen_at' => now(),
                ]
            );

            $this->replyWithChatId($record);
        } catch (\Throwable $e) {
            $this->logService->logException($e, null, 'DeliveryWebhookController');
        }

        return response()->json(['ok' => true]);
    }

    protected function replyWithChatId(TelegramChat $chat): void
    {
        try {
            $text = $chat->isGroupLike()
                ? "Chat ID nhận file: `{$chat->chat_id}`\nCopy ID này rồi nhập vào bot chính khi chạy /run hoặc /schedule."
                : "Hãy thêm bot gửi file này vào group cần nhận PDF. Sau khi vào group, bot sẽ trả về Chat ID để nhập ở bot chính.";

            Telegram::bot((string) config('telegram.delivery_bot', 'delivery'))->sendMessage([
                'chat_id' => $chat->chat_id,
                'text' => $text,
                'parse_mode' => 'Markdown',
            ]);
        } catch (\Throwable $e) {
            Log::warning("DeliveryWebhookController: failed to reply chat id — " . $e->getMessage());
        }
    }
}
