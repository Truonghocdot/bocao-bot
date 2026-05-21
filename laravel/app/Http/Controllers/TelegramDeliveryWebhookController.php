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

            if (isset($payload['my_chat_member'])) {
                return $this->handleMyChatMember($payload['my_chat_member']);
            }

            $message = data_get($payload, 'message') ?: data_get($payload, 'edited_message');
            
            if ($message) {
                return $this->handleMessage($message);
            }

        } catch (\Throwable $e) {
            $this->logService->logException($e, null, 'DeliveryWebhookController');
        }

        return response()->json(['ok' => true]);
    }

    protected function handleMyChatMember(array $myChatMember)
    {
        $chat = data_get($myChatMember, 'chat');
        if (! $chat) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) data_get($chat, 'id', '');
        if ($chatId === '') {
            return response()->json(['ok' => true]);
        }

        $newStatus = data_get($myChatMember, 'new_chat_member.status');
        $isMember = in_array($newStatus, ['member', 'administrator']);

        $record = TelegramChat::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'type' => data_get($chat, 'type'),
                'title' => data_get($chat, 'title'),
                'username' => data_get($chat, 'username'),
                'is_bot_member' => $isMember,
                'last_seen_at' => now(),
            ]
        );

        $oldStatus = data_get($myChatMember, 'old_chat_member.status');
        
        // Only reply if the bot was newly added to the group
        if ($isMember && !in_array($oldStatus, ['member', 'administrator'])) {
            $this->replyWithChatId($record);
        }

        return response()->json(['ok' => true]);
    }

    protected function handleMessage(array $message)
    {
        $chat = data_get($message, 'chat');
        if (! $chat) {
            return response()->json(['ok' => true]);
        }

        $chatId = (string) data_get($chat, 'id', '');
        if ($chatId === '') {
            return response()->json(['ok' => true]);
        }

        $record = TelegramChat::updateOrCreate(
            ['chat_id' => $chatId],
            [
                'type' => data_get($chat, 'type'),
                'title' => data_get($chat, 'title'),
                'username' => data_get($chat, 'username'),
                'is_bot_member' => true,
                'last_seen_at' => now(),
            ]
        );

        $text = data_get($message, 'text', '');
        $newMembers = data_get($message, 'new_chat_members', []);
        $botAdded = false;

        foreach ($newMembers as $member) {
            if (data_get($member, 'is_bot')) {
                $botAdded = true;
                break;
            }
        }

        // Reply if the bot was added via new_chat_members, or a command is called, or if it's a private chat
        if ($botAdded || data_get($message, 'group_chat_created') || str_starts_with($text, '/start') || str_starts_with($text, '/id') || !$record->isGroupLike()) {
            $this->replyWithChatId($record);
        }

        return response()->json(['ok' => true]);
    }

    protected function replyWithChatId(TelegramChat $chat): void
    {
        try {
            $text = $chat->isGroupLike()
                ? "Chat ID nhận file: <code>{$chat->chat_id}</code>\nCopy ID này rồi nhập vào bot chính khi chạy /run hoặc /schedule."
                : "Hãy thêm bot gửi file này vào group cần nhận PDF. Sau khi vào group, bot sẽ trả về Chat ID để nhập ở bot chính.";

            Telegram::bot((string) config('telegram.delivery_bot', 'delivery'))->sendMessage([
                'chat_id' => $chat->chat_id,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Throwable $e) {
            Log::warning("DeliveryWebhookController: failed to reply chat id — " . $e->getMessage());
        }
    }
}
