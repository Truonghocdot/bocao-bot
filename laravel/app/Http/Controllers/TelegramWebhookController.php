<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
use App\Models\TelegramUser;
use App\Services\TelegramCommandService;
use App\Services\TelegramLogService;

class TelegramWebhookController extends Controller
{
    protected $commandService;
    protected $logService;

    public function __construct(TelegramCommandService $commandService, TelegramLogService $logService)
    {
        $this->commandService = $commandService;
        $this->logService = $logService;
    }

    public function __invoke(Request $request)
    {
        try {
            $update = Telegram::getWebhookUpdate();
            $message = $update->getMessage();

            if (!$message) {
                return response()->json(['ok' => true]);
            }

            $chat = $message->getChat();
            $from = $message->getFrom();
            $chatId = (string) $chat->getId();
            $text = trim($message->getText() ?? '');

            // Lưu/cập nhật thông tin user để có thể resolve @username → chat_id sau này
            if ($from) {
                TelegramUser::updateOrCreate(
                    ['chat_id' => $chatId],
                    [
                        'username'   => $from->getUsername() ?: null,
                        'first_name' => $from->getFirstName() ?: null,
                        'last_name'  => $from->getLastName() ?: null,
                    ]
                );
            }

            $this->commandService->handleCommand($text, $chatId);

        } catch (\Throwable $e) {
            $this->logService->logException($e, null, 'WebhookController');
        }

        return response()->json(['ok' => true]);
    }
}