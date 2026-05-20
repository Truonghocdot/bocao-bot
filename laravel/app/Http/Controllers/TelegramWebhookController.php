<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
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
            $payload = $request->all();
            $message = data_get($payload, 'message')
                ?: data_get($payload, 'edited_message')
                ?: data_get($payload, 'callback_query.message');

            if (!$message) {
                return response()->json(['ok' => true]);
            }

            $chatId = (string) data_get($message, 'chat.id', '');
            if ($chatId === '') {
                return response()->json(['ok' => true]);
            }

            $from = data_get($payload, 'message.from')
                ?: data_get($payload, 'edited_message.from')
                ?: data_get($payload, 'callback_query.from');
            $text = trim((string) (
                data_get($message, 'text')
                ?: data_get($payload, 'callback_query.data')
                ?: ''
            ));

            // Lưu/cập nhật thông tin user để có thể resolve @username → chat_id sau này
            if ($from) {
                TelegramUser::updateOrCreate(
                    ['chat_id' => $chatId],
                    [
                        'username'   => data_get($from, 'username') ?: null,
                        'first_name' => data_get($from, 'first_name') ?: null,
                        'last_name'  => data_get($from, 'last_name') ?: null,
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
