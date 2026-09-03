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
            Log::info('DeliveryWebhookController received payload:', $payload);

            $chat = null;
            $isKicked = false;

            if (isset($payload['my_chat_member']['chat'])) {
                $chat = $payload['my_chat_member']['chat'];
                $status = data_get($payload['my_chat_member'], 'new_chat_member.status');
                if (in_array($status, ['left', 'kicked'])) {
                    $isKicked = true;
                }
            } elseif (isset($payload['chat_member']['chat'])) {
                $chat = $payload['chat_member']['chat'];
            } elseif (isset($payload['message']['chat'])) {
                $chat = $payload['message']['chat'];
            } elseif (isset($payload['edited_message']['chat'])) {
                $chat = $payload['edited_message']['chat'];
            } elseif (isset($payload['callback_query']['message']['chat'])) {
                $chat = $payload['callback_query']['message']['chat'];
            }

            if ($chat) {
                $chatId = (string) data_get($chat, 'id', '');
                
                if ($chatId !== '') {
                    $existingChat = TelegramChat::where('chat_id', $chatId)->first();

                    if (!$existingChat) {
                        Log::info("New chat detected: {$chatId}. Saving to DB.");
                        
                        $record = TelegramChat::create([
                            'chat_id' => $chatId,
                            'type' => data_get($chat, 'type'),
                            'title' => data_get($chat, 'title'),
                            'username' => data_get($chat, 'username'),
                            'is_bot_member' => !$isKicked,
                            'last_seen_at' => now(),
                        ]);

                        if (!$isKicked) {
                            Log::info("First time seeing chat {$chatId}. Sending reply.");
                            $this->replyWithChatId($record);
                        }
                    } else {
                        Log::info("Chat {$chatId} already exists. Updating info.");
                        $existingChat->update([
                            'type' => data_get($chat, 'type'),
                            'title' => data_get($chat, 'title'),
                            'username' => data_get($chat, 'username'),
                            'is_bot_member' => !$isKicked,
                            'last_seen_at' => now(),
                        ]);
                        
                        if (! $isKicked && $this->shouldReplyWithChatId($payload)) {
                            $this->replyWithChatId($existingChat);
                        }
                    }
                }
            } else {
                Log::info('No chat object found in payload.');
            }

        } catch (\Throwable $e) {
            Log::error("DeliveryWebhookController Error: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
            $this->logService->logException($e, null, 'DeliveryWebhookController');
        }

        return response()->json(['ok' => true]);
    }

    protected function shouldReplyWithChatId(array $payload): bool
    {
        $text = (string) data_get($payload, 'message.text', '');

        if (str_starts_with($text, '/start') || str_starts_with($text, '/id')) {
            return true;
        }

        return $this->isDeliveryBotAddedToChat($payload);
    }

    protected function isDeliveryBotAddedToChat(array $payload): bool
    {
        if (! isset($payload['my_chat_member'])) {
            return false;
        }

        $status = data_get($payload, 'my_chat_member.new_chat_member.status');

        return in_array($status, ['member', 'administrator', 'creator'], true);
    }

    protected function replyWithChatId(TelegramChat $chat): void
    {
        try {
            $text = $chat->isGroupLike()
                ? "Chat ID nhận file: <code>{$chat->chat_id}</code>\nCopy ID này rồi nhập vào bot chính khi chạy /run hoặc /schedule."
                : "Hãy thêm bot gửi file này vào group cần nhận PDF. Sau khi vào group, bot sẽ trả về Chat ID để nhập ở bot chính.";

            Log::info("Sending Chat ID to {$chat->chat_id}");

            $botName = (string) config('telegram.delivery_bot', 'delivery');
            if (!config("telegram.bots.{$botName}")) {
                Log::warning("Bot [{$botName}] is not configured in config/telegram.php. Falling back to 'delivery'.");
                $botName = 'delivery';
            }

            Telegram::bot($botName)->sendMessage([
                'chat_id' => $chat->chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
            Log::info("Successfully sent Chat ID to {$chat->chat_id}");
        } catch (\Throwable $e) {
            Log::warning("DeliveryWebhookController: failed to reply chat id — " . $e->getMessage());
        }
    }
}
