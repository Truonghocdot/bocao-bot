<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Laravel\Facades\Telegram;
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

            $chatId = $message->getChat()->getId();
            $text = trim($message->getText() ?? '');

            $this->commandService->handleCommand($text, $chatId);

        } catch (\Throwable $e) {
            $this->logService->logException($e, null, 'WebhookController');
        }

        return response()->json(['ok' => true]);
    }
}